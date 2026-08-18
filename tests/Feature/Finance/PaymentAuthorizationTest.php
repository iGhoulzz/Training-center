<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Policies\PaymentPolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| PaymentPolicy — the complete authorization surface (P2-T04, unit 4)
|--------------------------------------------------------------------------
|
| Design section 5: reading and recording a payment are permission-based
| (view_any_payment / view_payment / create_payment, the last seeded to
| admin as well as super_admin); reversing one is super-admin-only
| (reverse_payment); update_payment and delete_payment are deliberately not
| seeded at all, so update() and delete() answer a bare false rather than a
| permission check that could not succeed; and every other Filament ability
| PaymentPolicy did not previously state (deleteAny, restore, restoreAny,
| forceDelete, forceDeleteAny, replicate, reorder) refuses unconditionally
| too, closing the gap PolicyAbilitySurfaceTest currently reports.
|
| THE PANEL HALF OF THIS IS NOT HERE, DELIBERATELY. The plan also requires
| the policy to be exercised through the Filament panel itself, which needs
| PaymentResource — a later unit's file. That half lives in
| PaymentResourceTest once that resource exists; noting the split here so
| neither file reads as though it forgot the other's half.
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

    // readFor('payment') in RolePermissionSeeder: view_any_payment and
    // view_payment; create_payment is granted separately (FINANCE_WRITE).
    // reverse_payment is not granted to admin at all.
    $this->admin = ($this->actorWith)('admin');

    // Holds nothing financial at all — see RolePermissionSeeder's own
    // comment on the staff grant.
    $this->staff = ($this->actorWith)('staff');
});

/*
|--------------------------------------------------------------------------
| update_payment and delete_payment are not seeded
|--------------------------------------------------------------------------
*/

it('does not seed update_payment or delete_payment', function () {
    // Pins the seeder's deliberate omission (design section 5), so
    // re-adding either name later is a decision someone has to make rather
    // than drift nobody notices.
    expect(Permission::query()->where('name', 'update_payment')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'delete_payment')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Granting them anyway does not make the policy allow it
|--------------------------------------------------------------------------
*/

it('refuses update and delete for a super admin even when the permission is granted', function () {
    // THE ASSERTION THAT PROVES THE POLICY IGNORES THE GRANT (the
    // ActivityAppendOnlyTest / WriteOffChargeTest pattern). A permission
    // that does not exist proves nothing about a policy — the refusal
    // could just as easily be "nobody holds it". So the permissions are
    // created HERE, granted to the strongest actor there is, and the
    // refusal asserted anyway.
    $payment = Payment::factory()->create();

    foreach (['update_payment', 'delete_payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->superAdmin->givePermissionTo(['update_payment', 'delete_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdmin = $this->superAdmin->refresh();

    expect($superAdmin->can('update_payment'))->toBeTrue()
        ->and($superAdmin->can('delete_payment'))->toBeTrue();

    expect(Gate::forUser($superAdmin)->denies('update', $payment))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->denies('delete', $payment))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Every dormant ability refuses a super admin — the complete surface
|--------------------------------------------------------------------------
|
| A dataset over every Filament ability PaymentPolicy has not already been
| proven against above, rather than a handful of examples: testing only
| update() and delete() and calling the surface covered is a defect this
| project has shipped before (docs/ENGINEERING.md, "test the surface, not
| the instance you just fixed").
|
| needsRecord distinguishes the record-level abilities (restore,
| forceDelete, replicate — dormant because Payment never soft-deletes) from
| the class-level ones Filament authorizes without ever loading a row
| (deleteAny, restoreAny, forceDeleteAny, reorder).
*/

it('refuses every remaining dormant Filament ability for a super admin', function (string $ability, bool $needsRecord) {
    $payment = Payment::factory()->create();
    $subject = $needsRecord ? $payment : Payment::class;

    expect(Gate::forUser($this->superAdmin)->allows($ability, $subject))->toBeFalse(
        "PaymentPolicy::{$ability}() allowed a super admin. An unstated or permissive answer here "
        .'is exactly the gap that leaves Filament rendering a control nothing should show.',
    );
})->with([
    'deleteAny' => ['deleteAny', false],
    'restore' => ['restore', true],
    'restoreAny' => ['restoreAny', false],
    'forceDelete' => ['forceDelete', true],
    'forceDeleteAny' => ['forceDeleteAny', false],
    'replicate' => ['replicate', true],
    'reorder' => ['reorder', false],
]);

/*
|--------------------------------------------------------------------------
| RecordPaymentAction is denied to an actor without create_payment
|--------------------------------------------------------------------------
*/

it('denies RecordPaymentAction to an actor without create_payment, before anything is written', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    $thrown = null;

    try {
        app(RecordPaymentAction::class)->execute($this->staff, new RecordPaymentData(
            chargeId: (int) $charge->getKey(),
            allocation: '500.000',
            tenders: [new TenderData(TenderMethod::Cash, '500.000')],
            idempotencyKey: Str::uuid()->toString(),
        ));
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        // The refusal happens before anything is written, not after — the
        // Gate check runs before the charge is even loaded (see
        // RecordPaymentAction's own docblock).
        ->and(Payment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| ReversePaymentAction is denied to an admin
|--------------------------------------------------------------------------
*/

it('denies ReversePaymentAction to an admin, leaving reversed_at null', function () {
    // admin holds create_payment and both read permissions but not
    // reverse_payment — reverse_payment is seeded to super_admin alone.
    $payment = Payment::factory()->create();

    $thrown = null;

    try {
        app(ReversePaymentAction::class)->execute(
            $this->admin,
            (int) $payment->getKey(),
            'An admin attempting a reversal they do not hold reverse_payment for.',
        );
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($payment->fresh()->reversed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Discovery resolves the policy with no registration line
|--------------------------------------------------------------------------
*/

it('lets Laravel discover PaymentPolicy through convention, with no explicit registration', function () {
    expect(Gate::getPolicyFor(Payment::class))->toBeInstanceOf(PaymentPolicy::class);

    // THE SECOND ASSERTION IS WHAT MAKES THE FIRST MEAN ANYTHING. Without
    // it, the assertion above would keep passing if somebody later added
    // an explicit Gate::policy(Payment::class, ...) line, and this test
    // would silently stop proving the thing this unit is required to
    // prove — that discovery alone is sufficient. Task 4 deliberately adds
    // no such line: AppServiceProvider is shared with task 8 (payroll
    // runs) this wave, and the wave-isolation rule allows it exactly one
    // writer.
    $providerSource = File::get(app_path('Providers/AppServiceProvider.php'));

    $paymentPolicyRegistrations = collect(preg_split('/\r?\n/', $providerSource))
        ->filter(fn (string $line): bool => str_contains($line, 'Gate::policy(') && str_contains($line, 'Payment'))
        ->values()
        ->all();

    expect($paymentPolicyRegistrations)->toBeEmpty(
        'AppServiceProvider now registers PaymentPolicy explicitly, which this unit deliberately '
        .'avoided so the file keeps a single writer this wave: '.implode(', ', $paymentPolicyRegistrations),
    );
});
