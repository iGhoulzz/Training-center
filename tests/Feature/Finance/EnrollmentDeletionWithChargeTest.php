<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\DeleteEnrollmentAction;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Actions\AdjustChargeAction;
use App\Domain\Finance\Actions\WriteOffChargeAction;
use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Exceptions\ChargeAlreadyCommittedException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Deleting an enrolment that carries a bill
|--------------------------------------------------------------------------
|
| Design section 12: every enrolment now has a charge, and financial foreign
| keys restrict on delete — so without DeleteUncommittedChargeAction the first
| enrolment recorded in error would be permanent. P1-T11 shipped deletion as a
| separate grant from withdrawal for exactly that case.
|
| The rule is not "admins may delete bills". ChargePolicy::delete() still
| refuses everyone unconditionally (design section 4). This is the financial
| half of deleting an ENROLMENT, refused the moment the bill has money or a
| human decision attached to it.
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
    $this->admin = ($this->actorWith)('admin');

    $this->deleteEnrollment = app(DeleteEnrollmentAction::class);

    $this->payTowards = function (Charge $charge, string $amount): void {
        $payment = Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);
    };
});

/*
|--------------------------------------------------------------------------
| An untouched bill goes with its enrolment
|--------------------------------------------------------------------------
*/

it('deletes an enrolment together with its untouched bill', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $enrollment = $charge->enrollment;

    $this->deleteEnrollment->execute($this->admin, $enrollment);

    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeFalse()
        ->and(Charge::query()->whereKey($charge->getKey())->exists())->toBeFalse();

    // Read back through the query builder: the models above are in-memory copies.
    expect(DB::table('charges')->where('id', $charge->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Money attached: refused, and nothing half-removed
|--------------------------------------------------------------------------
*/

it('refuses to delete an enrolment whose bill has been paid against', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $enrollment = $charge->enrollment;

    ($this->payTowards)($charge, '300.000');

    $thrown = null;

    try {
        $this->deleteEnrollment->execute($this->admin, $enrollment);
    } catch (ChargeAlreadyCommittedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAlreadyCommittedException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey());

    // NEITHER row moved. The refusal propagates out of the transaction that was
    // going to remove both, so a half-deleted enrolment is not reachable.
    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue()
        ->and(Charge::query()->whereKey($charge->getKey())->exists())->toBeTrue();
});

it('refuses to delete an enrolment whose bill has been written off', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);
    $enrollment = $charge->enrollment;

    app(WriteOffChargeAction::class)->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'The centre has accepted this debt will never be collected.',
    ));

    $thrown = null;

    try {
        $this->deleteEnrollment->execute($this->admin, $enrollment);
    } catch (ChargeAlreadyCommittedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAlreadyCommittedException::class);

    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue()
        ->and(Charge::query()->whereKey($charge->getKey())->exists())->toBeTrue();
});

it('refuses to delete an enrolment whose bill has been corrected', function () {
    /*
     * THE CONDITION WITH NO COLUMN. Design section 4 gives `charges` no
     * adjustment columns because the append-only activity log IS that audit
     * record, so this is the one disqualifying fact that can only be read from
     * the log. If DeleteUncommittedChargeAction checked rows and columns alone,
     * a corrected bill — a figure a human deliberately changed, with a stated
     * reason — would delete silently.
     */
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    $enrollment = $charge->enrollment;

    app(AdjustChargeAction::class)->execute($this->superAdmin, new AdjustChargeData(
        (int) $charge->getKey(),
        '900.000',
        'The list price was mistyped at enrolment.',
    ));

    $thrown = null;

    try {
        $this->deleteEnrollment->execute($this->admin, $enrollment);
    } catch (ChargeAlreadyCommittedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAlreadyCommittedException::class)
        ->and($thrown->reason)->toContain('corrected');

    expect(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue()
        ->and(Charge::query()->whereKey($charge->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The policy is unchanged: nobody deletes a bill on its own
|--------------------------------------------------------------------------
*/

it('still refuses a direct charge deletion to everyone, including a super admin', function () {
    // DeleteUncommittedChargeAction is the financial half of deleting an
    // ENROLMENT. It is not a new answer to "may this actor delete a bill",
    // which design section 4 keeps at no for everyone.
    $charge = Charge::factory()->create();

    expect(Gate::forUser($this->superAdmin)->allows('delete', $charge))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $charge))->toBeFalse();
});
