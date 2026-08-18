<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\IdempotencyConflictException;
use App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Retry protection — replay, conflict, and what the fingerprint covers
|--------------------------------------------------------------------------
|
| Design section 5: atomic finalization plus a double-clicked button equals
| two payments and two receipts for one handover of cash, and nothing else in
| the design catches it because both submissions are individually valid.
|
| `payments.idempotency_key` is a client-generated UUID under a unique index.
| The key ALONE is not enough and returning whatever payment owns it is wrong:
| a key reused with a different bill or a different tender breakdown would hand
| back an unrelated receipt for money that was never recorded. So every payment
| also stores a canonical request fingerprint, and on a key collision the
| Action compares the two — identical is a replay and returns the existing
| payment, different raises IdempotencyConflictException and writes nothing.
|
| THE MECHANISM IS INSERT-FIRST, AND THAT IS THIS TASK'S CHOICE TO MAKE.
| Design section 5 states the required outcome and explicitly declines to
| prescribe the operation order, because the obvious sequence — resolve the
| key, compare, then lock — has a race of its own: two requests can both find
| no existing key before either inserts. Here the unique index decides. The
| second insert blocks until the first commits and then surfaces as a
| duplicate, which is routed to the replay path. That ordering is also what
| makes the settled-bill replay work; see that test for why.
|
| The concurrent half of these proofs cannot live in this file — RefreshDatabase
| holds a transaction open for the whole test, so single-connection locking
| proves nothing. It is in PaymentConcurrencyTest, which uses DatabaseTruncation
| and real subprocesses, exactly as CompensationConcurrencyTest already does for
| the compensation timeline.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($admin, 'admin');
    $this->admin = $admin->refresh();

    $this->recordPayment = app(RecordPaymentAction::class);

    /** A 300-card / 700-cash split against whatever bill and key are given. */
    $this->splitPayment = fn (int $chargeId, string $key, ?string $notes = null): RecordPaymentData => new RecordPaymentData(
        chargeId: $chargeId,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: $key,
        notes: $notes,
    );
});

/*
|--------------------------------------------------------------------------
| A genuine replay returns the payment that already exists
|--------------------------------------------------------------------------
*/

it('records one payment when the same key and request are submitted twice', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $first = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));
    $second = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(2)
        ->and(PaymentAllocation::query()->count())->toBe(1);

    /*
     * The second click gets the FIRST receipt — same row, same receipt number.
     * A second `RCT-` reference for one handover of cash is the outcome this
     * whole mechanism exists to prevent.
     */
    expect((int) $second->getKey())->toBe((int) $first->getKey())
        ->and($second->reference)->toBe($first->reference);
});

it('returns the original timestamp on a replay, even after the clock has moved', function () {
    $this->travelTo('2026-08-15 09:00:00');

    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    // An hour later: the same submission arriving again, from a retried
    // request or a browser tab that was left open.
    $this->travelTo('2026-08-15 10:00:00');

    $replay = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    /*
     * A LITERAL, not `$first->received_at`. `received_at` is generated by the
     * Action rather than supplied, and design section 5 makes the replay
     * returning the ORIGINAL instant the thing that keeps a reprinted receipt
     * identical to the one already handed over. Comparing the replay to the
     * first result would pass just as well against an Action that restamped
     * both.
     */
    expect($replay->received_at->toDateTimeString())->toBe('2026-08-15 09:00:00');
});

/*
|--------------------------------------------------------------------------
| The same key used for a DIFFERENT request is refused, not replayed
|--------------------------------------------------------------------------
*/

it('refuses the same key against a different bill, and creates nothing', function () {
    $chargeA = Charge::factory()->create(['amount' => '1000.000']);
    $chargeB = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $original = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $chargeA->getKey(), $key));

    $thrown = null;

    try {
        $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $chargeB->getKey(), $key));
    } catch (IdempotencyConflictException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(IdempotencyConflictException::class)
        ->and($thrown->existingPaymentId)->toBe((int) $original->getKey())
        ->and($thrown->idempotencyKey)->toBe($key);

    expect(Payment::query()->count())->toBe(1, 'A conflicting submission created a second payment.');

    // The money that WAS recorded still sits against the bill it was recorded
    // against — the conflict must not have moved it.
    $allocation = PaymentAllocation::query()->sole();

    expect((int) $allocation->charge_id)->toBe((int) $chargeA->getKey());
});

it('refuses the same key with a different tender split', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    // The same 1,000, split differently. What the customer handed over is not
    // what the first submission said, so this is not that submission.
    $differentSplit = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '500.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '500.000'),
        ],
        idempotencyKey: $key,
    );

    expect(fn () => $this->recordPayment->execute($this->admin, $differentSplit))
        ->toThrow(IdempotencyConflictException::class);

    expect(Payment::query()->count())->toBe(1);
});

it('refuses the same key when only the terminal reference differs', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    /*
     * THE CASE DESIGN SECTION 5 CALLS OUT BY NAME. Identical amounts, identical
     * split, a different terminal reference: the card machine approved TWICE.
     * Treating the second as a replay of the first would discard a real payment
     * while telling the operator it had been recorded — which is worse than the
     * duplicate the key exists to prevent.
     */
    $secondApproval = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-9999999999'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: $key,
    );

    expect(fn () => $this->recordPayment->execute($this->admin, $secondApproval))
        ->toThrow(IdempotencyConflictException::class);
});

/*
|--------------------------------------------------------------------------
| What the fingerprint deliberately does NOT distinguish
|--------------------------------------------------------------------------
*/

it('produces the same fingerprint whatever order the tenders arrive in', function () {
    $forwards = new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: 'shared-key',
    );

    $backwards = new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Cash, '700.000'),
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
        ],
        idempotencyKey: 'shared-key',
    );

    expect($forwards->fingerprint())->toBe($backwards->fingerprint());
});

it('gives a different fingerprint to every field that decides what money moved', function (RecordPaymentData $different, string $what) {
    /*
     * THE NEGATIVE CONTROL, and without it the two equality tests above are
     * assertions that agree with themselves: `fingerprint() === fingerprint()`
     * passes just as happily against an implementation returning a constant,
     * which would make every submission a replay of every other one and
     * silently discard real money.
     *
     * The conflict tests further up would also catch a constant fingerprint,
     * but only through the Action and the database. This pins the property on
     * the DTO itself, where it is defined, and names each field that must move
     * it.
     */
    $baseline = new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: 'shared-key',
    );

    expect($different->fingerprint())->not->toBe(
        $baseline->fingerprint(),
        "Two requests differing in {$what} share a fingerprint, so the second would be treated as a replay of the first.",
    );
})->with([
    'the bill' => [fn (): RecordPaymentData => new RecordPaymentData(
        chargeId: 8,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: 'shared-key',
    ), 'the bill'],
    'the allocation' => [fn (): RecordPaymentData => new RecordPaymentData(
        chargeId: 7,
        allocation: '900.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '600.000'),
        ],
        idempotencyKey: 'shared-key',
    ), 'the allocation'],
    'the tender split' => [fn (): RecordPaymentData => new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '500.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '500.000'),
        ],
        idempotencyKey: 'shared-key',
    ), 'the tender split'],
    'the tender method' => [fn (): RecordPaymentData => new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::BankTransfer, '700.000'),
        ],
        idempotencyKey: 'shared-key',
    ), 'the tender method'],
    'the terminal reference' => [fn (): RecordPaymentData => new RecordPaymentData(
        chargeId: 7,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-9999999999'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: 'shared-key',
    ), 'the terminal reference'],
]);

it('replays rather than conflicting when the tenders are resubmitted in another order', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $first = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    $reordered = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Cash, '700.000'),
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
        ],
        idempotencyKey: $key,
    );

    $replay = $this->recordPayment->execute($this->admin, $reordered);

    expect((int) $replay->getKey())->toBe((int) $first->getKey())
        ->and(Payment::query()->count())->toBe(1);
});

it('reads 700 and 700.000 as the same money, in the fingerprint and on replay', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $first = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    // The same submission with the trailing zeros left off — amounts are
    // fingerprinted as integer dirham precisely so this is not a new request.
    $unpadded = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000',
        tenders: [
            new TenderData(TenderMethod::Card, '300', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700'),
        ],
        idempotencyKey: $key,
    );

    expect($unpadded->fingerprint())->toBe(($this->splitPayment)((int) $charge->getKey(), $key)->fingerprint());

    $replay = $this->recordPayment->execute($this->admin, $unpadded);

    expect((int) $replay->getKey())->toBe((int) $first->getKey())
        ->and(Payment::query()->count())->toBe(1);
});

it('treats a changed note as the same payment, and keeps the original note', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    $first = $this->recordPayment->execute(
        $this->admin,
        ($this->splitPayment)((int) $charge->getKey(), $key, 'Paid at the front desk.'),
    );

    /*
     * Design section 5 excludes `notes` from the payload deliberately: it does
     * not change what was collected or against what, so two submissions
     * differing only in a typed note are the same payment recorded twice.
     * Fingerprinting them apart would defeat the mechanism in exactly the case
     * it exists for — an operator who retypes the form after a timeout.
     */
    $replay = $this->recordPayment->execute(
        $this->admin,
        ($this->splitPayment)((int) $charge->getKey(), $key, 'Paid at the desk, second attempt.'),
    );

    expect((int) $replay->getKey())->toBe((int) $first->getKey())
        ->and($replay->notes)->toBe('Paid at the front desk.')
        ->and(Payment::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Replay detection runs BEFORE revalidation
|--------------------------------------------------------------------------
*/

it('replays a payment that already settled its bill, instead of calling it an overpayment', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = Str::uuid()->toString();

    // This payment settles the bill completely: outstanding is now 0.000.
    $first = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));

    /*
     * THIS IS THE TEST THAT DECIDES THE OPERATION ORDER.
     *
     * The naive construction validates against outstanding first, and tells
     * the operator they are overpaying — when they are in fact looking at a
     * receipt that already exists. Because the insert sits ahead of
     * PaymentInvariantService::assertRecordable(), the unique index answers
     * first and the overpayment check is never reached on this path.
     *
     * The catch is explicit rather than left to surface as a test error, so
     * the failure says what actually broke: if the two are ever reordered,
     * every retried full settlement starts failing at the counter, and the
     * operator is told they are overpaying a bill they have already paid.
     */
    try {
        $replay = $this->recordPayment->execute($this->admin, ($this->splitPayment)((int) $charge->getKey(), $key));
    } catch (PaymentExceedsOutstandingException $exception) {
        $replay = null;

        $this->fail(
            'Replaying a payment that settled its bill raised an overpayment error, '.
            'so revalidation is running before replay detection.'
        );
    }

    expect((int) $replay->getKey())->toBe((int) $first->getKey())
        ->and(Payment::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The catch is narrow: only the idempotency index is converted
|--------------------------------------------------------------------------
*/

it('lets a unique violation on any other index surface as itself', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    // A payment already holding a real `RCT-` reference.
    $existing = Payment::factory()->create();

    /*
     * Force the Action's insert to collide on `payments_reference_unique`
     * instead of the idempotency index. 1062 alone means "some unique index
     * refused this row", which is not the same statement as "this key has been
     * used before" — and a confident, wrong message about a constraint nobody
     * was thinking about is worse than a raw driver error. EnrollStudentAction
     * checks the index name for the same reason.
     */
    Payment::creating(function (Payment $payment) use ($existing): void {
        $payment->reference = $existing->reference;
    });

    $thrown = null;

    try {
        $this->recordPayment->execute(
            $this->admin,
            ($this->splitPayment)((int) $charge->getKey(), Str::uuid()->toString()),
        );
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        Payment::flushEventListeners();
    }

    /*
     * The class assertion alone is the whole test. A second assertion that this
     * is NOT an IdempotencyConflictException used to sit here and was removed:
     * the two are disjoint hierarchies — UniqueConstraintViolationException
     * descends from PDOException, IdempotencyConflictException from
     * RuntimeException — so it could never fail whatever the Action did, and an
     * assertion that cannot fail reads as coverage while providing none.
     *
     * This test does have teeth: widening the catch to the 1062 code alone
     * makes the Action route this collision to the replay path, find no payment
     * under the key, and raise its own RuntimeException instead — which this
     * line catches.
     */
    expect($thrown)->toBeInstanceOf(UniqueConstraintViolationException::class);
});
