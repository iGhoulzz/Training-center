<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\PaymentAlreadyReversedException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| ReversePaymentAction — undoing a payment (P2-T04, unit 3)
|--------------------------------------------------------------------------
|
| Design section 5: reversal is a set-once lifecycle transition on an
| otherwise immutable row. Super admin only, via reverse_payment — seeded to
| super_admin alone (RolePermissionSeeder). `reversed_at`, `reversed_by` and
| `reversal_reason` are written once and never unset; the payment's financial
| facts — student, reference, tenders, allocations — are never rewritten and
| never deleted, and there is no delete path for a payment at all. A reversed
| payment drops out of every balance through exactly one mechanism —
| ChargeBalance's `payments.reversed_at IS NULL` filter — and nothing here
| proves that by deleting anything.
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
    // reverse_payment is not granted to admin at all — super admin only.
    $this->admin = ($this->actorWith)('admin');

    $this->recordPayment = app(RecordPaymentAction::class);
    $this->reverse = app(ReversePaymentAction::class);

    /**
     * A real payment recorded through RecordPaymentAction — 300 card and 700
     * cash against a fresh 1,000 bill — so every reversal test acts on rows
     * that exist the same way a production payment does. Each test builds
     * its own charge and payment; only the reversal itself is test-specific.
     *
     * @return array{0: Charge, 1: Payment}
     */
    $this->recordSplitPayment = function (): array {
        $charge = Charge::factory()->create(['amount' => '1000.000']);

        $payment = $this->recordPayment->execute($this->admin, new RecordPaymentData(
            chargeId: (int) $charge->getKey(),
            allocation: '1000.000',
            tenders: [
                new TenderData(TenderMethod::Card, '300.000', 'AUTH-REV-0001'),
                new TenderData(TenderMethod::Cash, '700.000'),
            ],
            idempotencyKey: Str::uuid()->toString(),
        ));

        return [$charge, $payment];
    };
});

/*
|--------------------------------------------------------------------------
| All three reversal columns, together
|--------------------------------------------------------------------------
*/

it('reverses a payment, setting all three reversal columns together', function () {
    $this->travelTo('2026-08-12 09:00:00');

    [, $payment] = ($this->recordSplitPayment)();

    $result = $this->reverse->execute(
        $this->superAdmin,
        (int) $payment->getKey(),
        'Recorded against the wrong bill.',
    );

    // A literal timestamp, not now() — this is the whole point of travelling
    // to a fixed instant first.
    expect($result->reversed_at->format('Y-m-d H:i:s'))->toBe('2026-08-12 09:00:00')
        ->and((int) $result->reversed_by)->toBe((int) $this->superAdmin->getKey())
        ->and($result->reversal_reason)->toBe('Recorded against the wrong bill.');

    // Read back through the query builder, the same discipline
    // WriteOffChargeTest uses for the write-off columns — the model holds
    // what the Action assigned in memory, the row holds what actually
    // committed. The CHECK constraint requires all three or none, so partial
    // persistence would fail loudly rather than quietly, but the values
    // themselves are worth reading back.
    $stored = DB::table('payments')->where('id', $payment->getKey())->first();

    expect($stored->reversed_at)->toBe('2026-08-12 09:00:00')
        ->and((int) $stored->reversed_by)->toBe((int) $this->superAdmin->getKey())
        ->and($stored->reversal_reason)->toBe('Recorded against the wrong bill.');
});

/*
|--------------------------------------------------------------------------
| Every tender and allocation row survives, unchanged
|--------------------------------------------------------------------------
*/

it('leaves every tender and allocation row intact after a reversal', function () {
    [$charge, $payment] = ($this->recordSplitPayment)();

    $tendersBefore = PaymentTender::query()
        ->where('payment_id', $payment->getKey())
        ->orderBy('method')
        ->get(['method', 'amount'])
        ->map(fn (PaymentTender $tender): array => [
            'method' => $tender->method->value,
            'amount' => $tender->amount,
        ])
        ->all();

    // Captured as a literal, asserted against directly, so this test cannot
    // pass merely by "before" and "after" moving together.
    expect($tendersBefore)->toBe([
        ['method' => 'card', 'amount' => '300.000'],
        ['method' => 'cash', 'amount' => '700.000'],
    ]);

    $allocationBefore = PaymentAllocation::query()->where('payment_id', $payment->getKey())->sole();

    expect($allocationBefore->amount)->toBe('1000.000')
        ->and((int) $allocationBefore->charge_id)->toBe((int) $charge->getKey());

    $allocationId = (int) $allocationBefore->getKey();

    $this->reverse->execute($this->superAdmin, (int) $payment->getKey(), 'Duplicate of an earlier receipt.');

    $tendersAfter = PaymentTender::query()
        ->where('payment_id', $payment->getKey())
        ->orderBy('method')
        ->get(['method', 'amount'])
        ->map(fn (PaymentTender $tender): array => [
            'method' => $tender->method->value,
            'amount' => $tender->amount,
        ])
        ->all();

    // Compared against the SAME literal as above, never against $tendersBefore.
    expect($tendersAfter)->toBe([
        ['method' => 'card', 'amount' => '300.000'],
        ['method' => 'cash', 'amount' => '700.000'],
    ]);

    $allocationAfter = PaymentAllocation::query()->where('payment_id', $payment->getKey())->sole();

    expect($allocationAfter->amount)->toBe('1000.000')
        ->and((int) $allocationAfter->charge_id)->toBe((int) $charge->getKey())
        ->and((int) $allocationAfter->getKey())->toBe($allocationId);
});

/*
|--------------------------------------------------------------------------
| The payment drops out of the balance
|--------------------------------------------------------------------------
*/

it('removes the payment from the bill balance once reversed, without removing the allocation', function () {
    [$charge] = $paymentPair = ($this->recordSplitPayment)();
    $payment = $paymentPair[1];

    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('0.000');

    $this->reverse->execute($this->superAdmin, (int) $payment->getKey(), 'This payment was never received.');

    /*
     * THE SECOND ASSERTION IS WHAT MAKES THE FIRST MEAN ANYTHING, and it was
     * added because a mutation survived without it. An Action that DELETED the
     * allocation would move outstanding from 0.000 to 1000.000 exactly as
     * effectively as `reversed_at` does, so the balance assertion alone passes
     * for a reversal that destroys the record of money the centre took.
     *
     * `payment_allocations` is what every balance in the system is summed
     * from, and design section 5 is explicit that a reversal rewrites and
     * deletes nothing: `payments.reversed_at IS NULL` in ChargeBalance's join
     * is the only thing that takes the money out of the figure. Naming both
     * halves here is what pins the mechanism rather than the outcome.
     */
    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('1000.000')
        ->and(PaymentAllocation::query()->where('payment_id', $payment->getKey())->count())->toBe(
            1,
            'The balance returned because the allocation was deleted, not because the payment was reversed.',
        );
});

/*
|--------------------------------------------------------------------------
| Nothing is deleted to achieve that
|--------------------------------------------------------------------------
*/

it('deletes nothing to remove the payment from the balance', function () {
    [, $payment] = ($this->recordSplitPayment)();

    expect(PaymentAllocation::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(2);

    $this->reverse->execute($this->superAdmin, (int) $payment->getKey(), 'Reversed without deleting anything.');

    // reversed_at is the only thing that takes the allocation out of the
    // balance (ChargeBalance) — the row itself is still exactly here.
    expect(PaymentAllocation::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| An admin cannot reverse
|--------------------------------------------------------------------------
*/

it('refuses an admin, even holding create_payment and both payment read permissions', function () {
    [, $payment] = ($this->recordSplitPayment)();

    expect($this->admin->can('view_any_payment'))->toBeTrue()
        ->and($this->admin->can('view_payment'))->toBeTrue()
        ->and($this->admin->can('create_payment'))->toBeTrue()
        ->and($this->admin->can('reverse_payment'))->toBeFalse();

    $thrown = null;

    try {
        $this->reverse->execute(
            $this->admin,
            (int) $payment->getKey(),
            'An admin attempting a reversal they do not hold reverse_payment for.',
        );
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($payment->fresh()->isReversed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Granting the permission is what allows it, not the role name
|--------------------------------------------------------------------------
*/

it('lets any actor holding reverse_payment succeed, not merely the super_admin role', function () {
    [, $payment] = ($this->recordSplitPayment)();

    // reverse_payment granted directly to the admin actor, who does not hold
    // (and never will, in production) the super_admin role. This proves
    // PaymentPolicy::reverse() answers on the permission, never on
    // hasRole('super_admin') — CLAUDE.md's non-negotiable #1.
    $this->admin->givePermissionTo('reverse_payment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = $this->admin->refresh();

    expect($admin->can('reverse_payment'))->toBeTrue();

    $result = $this->reverse->execute(
        $admin,
        (int) $payment->getKey(),
        'Granted reverse_payment directly, without the super_admin role.',
    );

    expect($result->isReversed())->toBeTrue()
        ->and((int) $result->reversed_by)->toBe((int) $admin->getKey());
});

/*
|--------------------------------------------------------------------------
| A second reversal is refused, not silently re-stamped
|--------------------------------------------------------------------------
*/

it('refuses a second reversal, leaving the original decision untouched', function () {
    $this->travelTo('2026-08-01 09:00:00');

    [, $payment] = ($this->recordSplitPayment)();

    $first = $this->reverse->execute(
        $this->superAdmin,
        (int) $payment->getKey(),
        'First decision: recorded against the wrong bill.',
    );

    $originalAt = $first->reversed_at;
    $originalBy = (int) $first->reversed_by;
    $originalReason = $first->reversal_reason;

    // Time passes, and a different super admin tries a second time.
    $this->travelTo('2026-08-05 09:00:00');
    $secondSuperAdmin = ($this->actorWith)('super_admin');

    $thrown = null;

    try {
        $this->reverse->execute(
            $secondSuperAdmin,
            (int) $payment->getKey(),
            'A second, later attempt at the same payment.',
        );
    } catch (PaymentAlreadyReversedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(PaymentAlreadyReversedException::class)
        ->and($thrown->paymentId)->toBe((int) $payment->getKey());

    $fresh = $payment->fresh();

    expect($fresh->reversed_at->equalTo($originalAt))->toBeTrue()
        ->and((int) $fresh->reversed_by)->toBe($originalBy)
        ->and($fresh->reversal_reason)->toBe($originalReason)
        ->and((int) $fresh->reversed_by)->not->toBe((int) $secondSuperAdmin->getKey());
});

/*
|--------------------------------------------------------------------------
| A mandatory reason
|--------------------------------------------------------------------------
*/

it('refuses a reversal whose reason is blank after trimming, and writes nothing', function () {
    [, $payment] = ($this->recordSplitPayment)();

    $thrown = null;

    try {
        $this->reverse->execute($this->superAdmin, (int) $payment->getKey(), '   ');
    } catch (InvalidArgumentException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($payment->fresh()->isReversed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The activity log's causer is the actor, not the session user
|--------------------------------------------------------------------------
|
| Spatie resolves a causer from the authenticated session unless told
| otherwise. $this->admin holds the session here while $this->superAdmin is
| the actor the Action is called with — both must disagree with the session
| for this test to mean anything, which is why the two roles are
| deliberately different actors rather than two copies of the same one.
*/

/**
 * The causer recorded against this payment's most recent update entry.
 *
 * Null means the entry named nobody at all, a distinct failure from naming
 * the wrong person.
 */
function reversalCauserId(Payment $payment): ?int
{
    $causerId = Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->value('causer_id');

    return $causerId === null ? null : (int) $causerId;
}

it('attributes the reversal to the Action actor, not to whoever holds the session', function () {
    $this->actingAs($this->admin);

    [, $payment] = ($this->recordSplitPayment)();

    $this->reverse->execute(
        $this->superAdmin,
        (int) $payment->getKey(),
        'Reversed by a super admin while an admin holds the session.',
    );

    expect(reversalCauserId($payment))->toBe((int) $this->superAdmin->getKey())
        ->and(reversalCauserId($payment))->not->toBe((int) $this->admin->getKey());

    // The diff a reader actually opens carries all three reversal columns —
    // they are in Payment::auditedAttributes() — proving the automatic
    // model-event log, not a hand-built entry, is what recorded this.
    $entry = Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    $changes = ActivityResource::describeChanges($entry);

    expect($changes)->toContain('reversed_at')
        ->and($changes)->toContain('reversed_by')
        ->and($changes)->toContain('reversal_reason');
});

/*
|--------------------------------------------------------------------------
| The database refuses a partial reversal independently of this Action
|--------------------------------------------------------------------------
*/

it('refuses a partial reversal at the database CHECK, independent of the Action', function () {
    [, $payment] = ($this->recordSplitPayment)();

    // A direct write bypassing ReversePaymentAction entirely, setting only
    // one of the three reversal columns — proves the constraint, not the
    // Action.
    $partialUpdate = fn () => DB::table('payments')
        ->where('id', $payment->getKey())
        ->update(['reversed_at' => now()]);

    expect($partialUpdate)->toThrow(QueryException::class);
});
