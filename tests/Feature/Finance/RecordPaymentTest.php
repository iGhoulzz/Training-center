<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException;
use App\Domain\Finance\Exceptions\TenderAllocationMismatchException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| RecordPaymentAction — recording money against a bill (P2-T04, unit 1)
|--------------------------------------------------------------------------
|
| Design section 5: a payment is one or more tenders against exactly one
| bill, self-consistent (tenders sum to the allocation) and not larger than
| what the bill still owes, with the outstanding figure derived under the
| charge row's own lock so a bill settled between form load and submit
| produces a clean refusal. The student is never supplied — it is derived
| from the locked charge through EnrollmentQueryService — and the receipt
| reference is dated on the centre's local calendar, not on UTC.
|
| Idempotency (the unique-key replay path) is a later unit's work and is not
| exercised here; the idempotency_key and request_fingerprint columns are
| still written on every insert because both are NOT NULL.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->superAdmin = ($this->actorWith)('super_admin');

    // readFor('payment') in RolePermissionSeeder gives admin view_any_payment
    // and view_payment; create_payment is granted separately (FINANCE_WRITE).
    $this->admin = ($this->actorWith)('admin');

    $this->recordPayment = app(RecordPaymentAction::class);
});

/*
|--------------------------------------------------------------------------
| The receipt reference is dated on the centre's calendar, not on UTC
|--------------------------------------------------------------------------
*/

it('dates the receipt reference on the centre calendar, not on UTC', function () {
    /*
     * 22:30 UTC on 31 December is 00:30 on 1 January in Africa/Tripoli, and
     * that hour is the ONLY window where this property can fail. For the other
     * 22 hours of the day the two calendars name the same year, so a test
     * pinned at any ordinary time passes against code reading either one and
     * proves nothing.
     */
    $this->travelTo('2026-12-31 22:30:00');

    $charge = Charge::factory()->create(['amount' => '200.000']);

    $payment = $this->recordPayment->execute($this->admin, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '200.000',
        tenders: [new TenderData(TenderMethod::Cash, '200.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    /*
     * THE YEAR IS A LITERAL, and it is the whole point of this test. Building
     * it from CentreCalendar::yearOf() or Reference::format() would move the
     * expectation with the code under test, so a broken conversion would leave
     * both sides agreeing and the assertion green.
     *
     * THE ID SEGMENT IS READ BACK OFF THE ROW, and that is not a weakening.
     * An earlier version of this test pinned the whole string as
     * `RCT-2027-000001`, which passed when the file ran alone and failed the
     * moment any earlier file recorded a payment: InnoDB does not reclaim an
     * auto_increment value consumed inside a transaction that RefreshDatabase
     * later rolled back, so the id is a property of suite ordering rather than
     * of this Action. The padding is spelled out here rather than called,
     * so the format stays pinned without the expectation being borrowed from
     * the code that produces it.
     */
    $paddedId = str_pad((string) $payment->getKey(), 6, '0', STR_PAD_LEFT);

    expect($payment->reference)->toBe("RCT-2027-{$paddedId}");
});

/*
|--------------------------------------------------------------------------
| A split payment settles the bill in full
|--------------------------------------------------------------------------
*/

it('records a split payment of 300 card and 700 cash against a 1,000 bill', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    $data = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: Str::uuid()->toString(),
    );

    $payment = $this->recordPayment->execute($this->admin, $data);

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(2)
        ->and(PaymentAllocation::query()->count())->toBe(1);

    $tendersByMethod = PaymentTender::query()
        ->where('payment_id', $payment->getKey())
        ->get()
        ->keyBy(fn (PaymentTender $tender): string => $tender->method->value);

    expect($tendersByMethod->has('card'))->toBeTrue()
        ->and($tendersByMethod->has('cash'))->toBeTrue()
        ->and($tendersByMethod->get('card')->amount)->toBe('300.000')
        ->and($tendersByMethod->get('card')->external_reference)->toBe('AUTH-1234567890')
        ->and($tendersByMethod->get('cash')->amount)->toBe('700.000');

    $allocation = PaymentAllocation::query()->where('payment_id', $payment->getKey())->sole();

    expect((int) $allocation->charge_id)->toBe((int) $charge->getKey())
        ->and($allocation->amount)->toBe('1000.000');

    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->equals(Money::zero()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A tender/allocation mismatch is refused, and leaves no partial row
|--------------------------------------------------------------------------
*/

it('refuses a payment whose tenders do not sum to the allocation, leaving no partial row', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    $data = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        // 900 tendered against a 1,000 allocation — inconsistent with itself.
        tenders: [new TenderData(TenderMethod::Cash, '900.000')],
        idempotencyKey: Str::uuid()->toString(),
    );

    $thrown = null;

    try {
        $this->recordPayment->execute($this->admin, $data);
    } catch (TenderAllocationMismatchException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(TenderAllocationMismatchException::class);

    /*
     * THE ZERO PAYMENT COUNT IS THE "NO PARTIAL ROW" PROOF.
     * The payment row is inserted BEFORE assertRecordable() runs (see
     * RecordPaymentAction's own docblock for why), so this only passes if
     * the transaction actually rolled the insert back rather than merely
     * refusing to write the tenders and allocation that would have
     * followed it.
     */
    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentTender::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Paying more than outstanding is refused
|--------------------------------------------------------------------------
*/

it('refuses a payment that would allocate more than the bill still owes', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    // 400 already allocated from a payment that still stands, leaving 600
    // outstanding. PaymentAllocationFactory mints its own Payment parent, so
    // one payment legitimately exists before this attempt.
    PaymentAllocation::factory()->create([
        'charge_id' => $charge->getKey(),
        'amount' => '400.000',
    ]);

    $paymentsBeforeAttempt = Payment::query()->count();

    $data = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '700.000',
        tenders: [new TenderData(TenderMethod::Cash, '700.000')],
        idempotencyKey: Str::uuid()->toString(),
    );

    $thrown = null;

    try {
        $this->recordPayment->execute($this->admin, $data);
    } catch (PaymentExceedsOutstandingException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(PaymentExceedsOutstandingException::class)
        // A literal, never ChargeBalance::outstandingFor(...) computed here —
        // that would move with the code under test and prove nothing.
        ->and($thrown->outstandingAmount)->toBe('600.000')
        ->and($thrown->attemptedAmount)->toBe('700.000');

    expect(Payment::query()->count())->toBe(
        $paymentsBeforeAttempt,
        'A payment row survived a refused overpayment attempt.',
    );
});

/*
|--------------------------------------------------------------------------
| The student is derived from the bill, not supplied
|--------------------------------------------------------------------------
*/

it('derives the paying student from the locked charge, never from caller input', function () {
    $studentA = Student::factory()->create();
    $enrollmentA = Enrollment::factory()->create(['student_id' => $studentA->getKey()]);
    $chargeA = Charge::factory()->create([
        'enrollment_id' => $enrollmentA->getKey(),
        'amount' => '500.000',
    ]);

    // An unrelated student, present only to prove nothing points at them.
    $studentB = Student::factory()->create();

    $data = new RecordPaymentData(
        chargeId: (int) $chargeA->getKey(),
        allocation: '500.000',
        tenders: [new TenderData(TenderMethod::Cash, '500.000')],
        idempotencyKey: Str::uuid()->toString(),
    );

    $payment = $this->recordPayment->execute($this->admin, $data);

    expect((int) $payment->student_id)->toBe((int) $studentA->getKey())
        ->and((int) $payment->student_id)->not->toBe((int) $studentB->getKey());

    /*
     * THERE IS NO FIELD TO PASS STUDENT B IN — enforced, not merely
     * asserted in prose. RecordPaymentData's constructor takes chargeId,
     * allocation, tenders, idempotencyKey and notes; a student id was never
     * a parameter it could accept.
     */
    $reflection = new ReflectionClass(RecordPaymentData::class);

    $names = collect($reflection->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName())
        ->merge(collect($reflection->getConstructor()?->getParameters() ?? [])
            ->map(fn (ReflectionParameter $parameter): string => $parameter->getName()));

    $studentLike = $names->first(fn (string $name): bool => str_contains(strtolower($name), 'student'));

    expect($studentLike)->toBeNull(
        "RecordPaymentData carries a student-shaped member ({$studentLike}), which is exactly the crafted-request risk design section 5 rules out.",
    );
});

/*
|--------------------------------------------------------------------------
| Outstanding dropping between form load and submit
|--------------------------------------------------------------------------
*/

it('refuses a second payment once the bill has already been settled in full', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    // The first payment settles the bill completely.
    $this->recordPayment->execute($this->admin, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '500.000',
        tenders: [new TenderData(TenderMethod::Cash, '500.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    // A second attempt, with a fresh idempotency key — as if a second
    // browser tab had the same form open and submitted after the first.
    $second = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '500.000',
        tenders: [new TenderData(TenderMethod::Cash, '500.000')],
        idempotencyKey: Str::uuid()->toString(),
    );

    $thrown = null;

    try {
        $this->recordPayment->execute($this->admin, $second);
    } catch (PaymentExceedsOutstandingException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(PaymentExceedsOutstandingException::class)
        ->and($thrown->outstandingAmount)->toBe('0.000');

    expect(Payment::query()->count())->toBe(
        1,
        'A second payment was recorded against a bill that outstanding already showed as settled.',
    );
});

/*
|--------------------------------------------------------------------------
| A blank-after-trim card reference is refused, at the database and at the
| DTO boundary — two separate proofs of two separate things
|--------------------------------------------------------------------------
*/

it('refuses a card tender whose reference is blank after trimming, at the database CHECK', function () {
    // Proves the constraint, not the DTO — a direct insert bypasses
    // TenderData entirely.
    $payment = Payment::factory()->create();

    $insert = fn () => DB::table('payment_tenders')->insert([
        'payment_id' => $payment->getKey(),
        'method' => 'card',
        'amount' => '100.000',
        // A tab is blank by every meaning that matters, but survives
        // MySQL's single-argument TRIM(), which strips spaces only — see
        // the payment_tenders migration's own docblock on this exact point.
        'external_reference' => "\t",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);
});

it('refuses a card TenderData whose reference is blank after trimming, at the DTO boundary', function () {
    // The DTO's own refusal — a separate assertion from the database CHECK
    // above, so a divergence between the two is caught rather than assumed.
    expect(fn () => new TenderData(TenderMethod::Card, '100.000', "\t"))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Atomicity under a mid-write failure
|--------------------------------------------------------------------------
*/

it('rolls back the whole payment when a tender write fails partway through', function () {
    /*
     * NOT A TRANSACTION-DEPTH COMPARISON — see EnrollAndBillTest's own
     * reasoning for why that comparison passes for an Action that opens no
     * transaction at all. The proof here is a failure injected AFTER the
     * payment row already exists, while a tender is being written, and an
     * assertion that nothing survived it.
     */
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    PaymentTender::creating(function (): void {
        throw new RuntimeException('The tender could not be written.');
    });

    $data = new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-9999999999'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: Str::uuid()->toString(),
    );

    $thrown = null;

    try {
        $this->recordPayment->execute($this->admin, $data);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        // Model event listeners outlive the test otherwise.
        PaymentTender::flushEventListeners();
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    expect(Payment::query()->count())->toBe(
        0,
        'A payment row survived a failed tender write, so the write was not atomic.',
    );
    expect(PaymentTender::query()->count())->toBe(0);
    expect(PaymentAllocation::query()->count())->toBe(0);

    // Read past Eloquent, in case anything above is answering from memory.
    expect(DB::table('payments')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The charge row is locked
|--------------------------------------------------------------------------
|
| Modest on purpose: the real proof that the lock blocks a concurrent writer
| is PaymentConcurrencyTest, in subprocesses. This is the supporting
| assertion that `lockCharge()` takes the lock, and that it takes it BEFORE
| the payment is inserted.
|
| THE ORDERING IS WHAT THIS TEST IS FOR, AND IT WAS NOT ALWAYS.
| An earlier version asserted only `locksOn($statements, 'charges') !== []`,
| which the independent review showed could no longer fail: `locksOn()`
| matches ANY locking read of `charges`, and once
| `ChargeBalance::outstandingForUpdate()` began taking the charge row's lock
| itself — from inside `assertRecordable()`, after the insert — that
| statement satisfied the assertion on its own. Deleting `lockForUpdate()`
| from `lockCharge()` left the entire Finance suite green.
|
| So the property worth pinning is not "some lock happened" but "the bill was
| locked before this transaction wrote anything against it". That is what
| serialises two tills, and it is the sentence PaymentInvariantService's
| docblock makes.
*/

it('locks the charge row before it inserts the payment', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    $statements = captureStatements();

    $this->recordPayment->execute($this->admin, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '500.000',
        tenders: [new TenderData(TenderMethod::Cash, '500.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    $sql = collect($statements)->pluck('sql');

    $firstChargeLock = $sql->search(
        fn (string $statement): bool => str_contains($statement, ' for update')
            && str_contains($statement, 'from `charges`'),
    );

    $firstPaymentInsert = $sql->search(
        fn (string $statement): bool => str_starts_with($statement, 'insert into `payments`'),
    );

    expect($firstChargeLock)->not->toBeFalse(
        'RecordPaymentAction never read the charge `for update`. Statements seen: '.describeStatements($statements),
    );

    expect($firstPaymentInsert)->not->toBeFalse(
        'No payment was inserted, so this test cannot say anything about ordering.',
    );

    expect($firstChargeLock)->toBeLessThan(
        $firstPaymentInsert,
        'The charge was locked only AFTER the payment row was inserted, so the bill was not held '
        .'while this transaction decided what to write against it. Statements seen: '
        .describeStatements($statements),
    );
});

/*
|--------------------------------------------------------------------------
| The activity log's causer is the actor, not the session user
|--------------------------------------------------------------------------
|
| Spatie resolves a causer from the authenticated session unless told
| otherwise. `$this->admin` holds the session here while `$this->superAdmin`
| is the actor the Action is called with — both must disagree with the
| session for this test to mean anything, which is why the two roles are
| deliberately different actors rather than two copies of the same one.
*/

it('attributes the payment to the Action actor, not to whoever holds the session', function () {
    $this->actingAs($this->admin);

    $charge = Charge::factory()->create(['amount' => '400.000']);

    $payment = $this->recordPayment->execute($this->superAdmin, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '400.000',
        tenders: [new TenderData(TenderMethod::Cash, '400.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    expect((int) $payment->recorded_by)->toBe((int) $this->superAdmin->getKey());

    $causerId = Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('event', 'created')
        ->latest('id')
        ->value('causer_id');

    expect($causerId)->not->toBeNull()
        ->and((int) $causerId)->toBe((int) $this->superAdmin->getKey())
        ->and((int) $causerId)->not->toBe((int) $this->admin->getKey());
});

/*
|--------------------------------------------------------------------------
| No placeholder survives
|--------------------------------------------------------------------------
*/

it('never leaves the reference placeholder on the row or in the activity log', function () {
    $charge = Charge::factory()->create(['amount' => '300.000']);

    $payment = $this->recordPayment->execute($this->admin, new RecordPaymentData(
        chargeId: (int) $charge->getKey(),
        allocation: '300.000',
        tenders: [new TenderData(TenderMethod::Cash, '300.000')],
        idempotencyKey: Str::uuid()->toString(),
    ));

    expect(Reference::isPlaceholder($payment->reference))->toBeFalse();

    // The placeholder exists only inside the transaction, and never in the
    // append-only log — `reference` is excluded from
    // Payment::auditedAttributes() precisely so it cannot reach it.
    $placeholdersLogged = Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('properties', 'like', '%'.Reference::PLACEHOLDER_MARKER.'%')
        ->count();

    expect($placeholdersLogged)->toBe(0);
});
