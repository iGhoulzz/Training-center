<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\AdjustChargeAction;
use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Exceptions\ChargeAmountBelowAllocatedException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Support\Money;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AdjustChargeAction — correcting a data-entry error, never a late discount
|--------------------------------------------------------------------------
|
| Design section 4: super admin only, a mandatory reason, refused below the
| allocated total, and the correction is allowed to leave `amount` disagreeing
| with `list_price x (100 - percentage) / 100` forever after. The activity log
| is the only place the reason lives — `charges` carries no adjustment columns
| at all — so the log entry itself is the thing under test, not merely its
| existence.
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

    // `readFor('charge')` in RolePermissionSeeder: view_any_charge and
    // view_charge, and nothing else finance-related that matters to this file.
    $this->admin = ($this->actorWith)('admin');

    $this->adjust = app(AdjustChargeAction::class);

    /**
     * A payment that still stands (or has been reversed), allocating $amount to
     * $charge. Mirrors ChargeBalanceTest's own helper, because ChargeBalance is
     * the single definition AdjustChargeAction reads under lock, and a test that
     * built allocations a different way would risk exercising a different path.
     */
    $this->payTowards = function (Charge $charge, string $amount, bool $reversed = false): Payment {
        $payment = $reversed
            ? Payment::factory()->reversed()->create()
            : Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };
});

/*
|--------------------------------------------------------------------------
| A super admin corrects the amount
|--------------------------------------------------------------------------
*/

it('adjusts a charges amount for a super admin', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    $result = $this->adjust->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '750.000',
        'The list price was mistyped at enrolment.',
    ));

    expect($result->amount)->toBe('750.000');

    // Read back through the query builder, not off the returned model — the
    // model holds whatever the Action assigned in memory, the column holds
    // what the transaction actually committed.
    expect((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('750.000');
});

/*
|--------------------------------------------------------------------------
| A reason is mandatory
|--------------------------------------------------------------------------
|
| Validated in AdjustChargeData's own constructor, not in the Action — a
| malformed request never reaches the Action at all. Both the empty string and
| whitespace-only are covered, since trim() is what separates "no reason" from
| "a reason that happens to be blank padding".
*/

it('refuses an adjustment with no reason', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    expect(fn () => new AdjustChargeData((int) $charge->getKey(), '400.000', ''))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AdjustChargeData((int) $charge->getKey(), '400.000', '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($charge->fresh()->amount)->toBe('500.000');
});

/*
|--------------------------------------------------------------------------
| The refusal below the allocated total — from both sides
|--------------------------------------------------------------------------
*/

it('refuses to drop the amount below what has already been allocated', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($charge, '300.000');

    $thrown = null;

    try {
        $this->adjust->execute($this->superAdmin, new AdjustChargeData(
            (int) $charge->getKey(),
            '299.999',
            'Attempting to drop one dirham below what has been paid.',
        ));
    } catch (ChargeAmountBelowAllocatedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAmountBelowAllocatedException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey())
        ->and($thrown->attemptedAmount)->toBe('299.999')
        ->and($thrown->allocatedAmount)->toBe('300.000');

    // A refusal must not half-apply — the amount is exactly where it started.
    expect((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('1000.000');
});

it('allows the amount to be set exactly to the allocated total', function () {
    // THE BOUNDARY. isLessThan() refuses 299.999 above and must not also
    // refuse the value it is compared against — an off-by-one here would
    // block every bill correction that lands exactly on what was paid.
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($charge, '300.000');

    $result = $this->adjust->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '300.000',
        'Correcting the amount down to exactly what has been paid so far.',
    ));

    expect($result->amount)->toBe('300.000')
        ->and((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('300.000');
});

/*
|--------------------------------------------------------------------------
| A reversed payment's allocation does not count
|--------------------------------------------------------------------------
|
| The single most-tested property in the phase (ChargeBalanceTest exercises it
| directly against ChargeBalance; this proves AdjustChargeAction actually reads
| ChargeBalance rather than summing allocations a second way).
*/

it('lets a charge whose only payment was reversed be adjusted down freely', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($charge, '900.000', reversed: true);

    // If the reversed payment counted, dropping to 50.000 would be refused —
    // 900.000 allocated is well above it.
    $result = $this->adjust->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '50.000',
        'A reversed payment must not block this correction.',
    ));

    expect($result->amount)->toBe('50.000')
        ->and((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('50.000');
});

/*
|--------------------------------------------------------------------------
| The reason reaches the activity log, alongside the before/after diff
|--------------------------------------------------------------------------
|
| Filtered to event = 'updated': Charge::factory()->create() itself files a
| 'created' entry (list_price, amount and the rest are all audited attributes),
| so counting every entry for this subject would not isolate what the Action
| wrote. Exactly one 'updated' entry is the claim — AdjustChargeAction disables
| the automatic model-event log for this write and builds its own instead, so
| two 'updated' entries would mean the automatic one leaked through carrying
| the diff with no reason attached to it.
*/

it('writes the reason and the before/after diff to one activity entry', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    $this->adjust->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '750.000',
        'The enrolment fee was entered twice.',
    ));

    $updates = Activity::query()
        ->where('subject_type', Charge::class)
        ->where('subject_id', $charge->getKey())
        ->where('event', 'updated')
        ->get();

    expect($updates)->toHaveCount(
        1,
        'Expected exactly one "updated" entry. AdjustChargeAction disables the automatic '
        .'model-event log for this write and files its own; a second entry here means the '
        .'automatic one fired too, carrying the amount diff with no reason attached.',
    );

    $entry = $updates->first();

    // The two sub-arrays are asserted independently rather than as one nested
    // structure: MySQL's JSON column does not guarantee the member order it was
    // written with, and the content — not the incidental key order — is the
    // claim design section 4 makes.
    expect($entry->causer_id)->toBe((int) $this->superAdmin->getKey())
        ->and($entry->getProperty('reason'))->toBe('The enrolment fee was entered twice.')
        ->and($entry->attribute_changes?->get('attributes'))->toBe(['amount' => '750.000'])
        ->and($entry->attribute_changes?->get('old'))->toBe(['amount' => '1000.000']);
});

/*
|--------------------------------------------------------------------------
| An admin cannot adjust, even holding every seeded charge read permission
|--------------------------------------------------------------------------
*/

it('refuses an admin, even holding every seeded charge read permission', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    // The permission this admin genuinely holds — proving the refusal is not
    // merely "this actor holds nothing at all".
    expect($this->admin->can('view_any_charge'))->toBeTrue()
        ->and($this->admin->can('view_charge'))->toBeTrue();

    $thrown = null;

    try {
        $this->adjust->execute($this->admin, new AdjustChargeData(
            (int) $charge->getKey(),
            '500.000',
            'An admin attempting a correction they do not hold adjust_charge for.',
        ));
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('1000.000');
});

/*
|--------------------------------------------------------------------------
| After an adjustment, the rounding rule is allowed to disagree
|--------------------------------------------------------------------------
*/

it('tolerates the amount no longer matching list_price times the discount rule after an adjustment', function () {
    $discount = Discount::factory()->create(['percentage' => '10.00']);
    $charge = Charge::factory()->withDiscount($discount)->create(['list_price' => '1000.000']);

    // The rounding rule (design section 3), confirmed true before it is broken.
    expect($charge->amount)->toBe('900.000');

    $result = $this->adjust->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '350.000',
        'Correcting a data-entry mistake, unrelated to the discount that was applied.',
    ));

    $ruleResult = Money::fromDecimal((string) $charge->list_price)
        ->afterDiscount((string) $charge->discount_percentage)
        ->toDecimal();

    expect($ruleResult)->toBe('900.000')
        ->and($result->amount)->toBe('350.000')
        ->and($result->amount)->not->toBe($ruleResult)
        ->and($result->fresh()->amount)->toBe('350.000')
        // The frozen figures the correction is measured against stay frozen —
        // only amount moved.
        ->and($result->list_price)->toBe('1000.000')
        ->and($result->discount_percentage)->toBe('10.00');
});
