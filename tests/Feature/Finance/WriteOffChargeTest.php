<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\WriteOffChargeAction;
use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Exceptions\ChargeAlreadyWrittenOffException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| WriteOffChargeAction — retiring a debt the centre will never collect
|--------------------------------------------------------------------------
|
| Design section 4: super admin only, a mandatory reason, nothing erased, and a
| second write-off refused outright rather than silently re-stamping a new
| actor and timestamp over an earlier decision. Section 4 also settles that
| `create_charge`, `update_charge` and `delete_charge` are deliberately not
| seeded and that ChargePolicy refuses them unconditionally — proven here the
| way ActivityAppendOnlyTest proves the same shape for the activity log: grant
| the permission anyway and show the policy still says no.
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

    // readFor('charge') in RolePermissionSeeder: view_any_charge and
    // view_charge only.
    $this->admin = ($this->actorWith)('admin');

    $this->writeOff = app(WriteOffChargeAction::class);
});

/*
|--------------------------------------------------------------------------
| All three columns, together
|--------------------------------------------------------------------------
*/

it('writes off a charge, setting all three write-off columns together', function () {
    $this->travelTo('2026-08-12 09:00:00');

    $charge = Charge::factory()->create(['amount' => '750.000']);

    $result = $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'The centre has accepted this debt will never be collected.',
    ));

    expect($result->written_off_at)->not->toBeNull()
        ->and((int) $result->written_off_by)->toBe((int) $this->superAdmin->getKey())
        ->and($result->written_off_reason)->toBe('The centre has accepted this debt will never be collected.');

    // Read back through the query builder, the same discipline EnrollmentTest
    // uses for the ENR- reference — the model holds what the Action assigned
    // in memory, the row holds what actually committed. A CHECK constraint
    // requires all three or none, so partial persistence would fail loudly
    // rather than quietly, but the values themselves are worth reading back.
    $stored = DB::table('charges')->where('id', $charge->getKey())->first();

    expect($stored->written_off_at)->not->toBeNull()
        ->and((int) $stored->written_off_by)->toBe((int) $this->superAdmin->getKey())
        ->and($stored->written_off_reason)->toBe('The centre has accepted this debt will never be collected.');
});

/*
|--------------------------------------------------------------------------
| A mandatory reason
|--------------------------------------------------------------------------
*/

it('refuses a write-off with no reason', function () {
    $charge = Charge::factory()->create();

    expect(fn () => new WriteOffChargeData((int) $charge->getKey(), ''))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new WriteOffChargeData((int) $charge->getKey(), '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($charge->fresh()->isWrittenOff())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The debt stays visible — nothing is erased
|--------------------------------------------------------------------------
*/

it('keeps the debt and its amount visible after it is written off', function () {
    $charge = Charge::factory()->create(['amount' => '620.000']);
    $studentId = (int) $charge->enrollment->student_id;

    $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'Uncollectable after repeated attempts to reach the student.',
    ));

    $fresh = Charge::query()->find($charge->getKey());

    expect($fresh)->not->toBeNull()
        ->and($fresh->amount)->toBe('620.000')
        ->and($fresh->isWrittenOff())->toBeTrue();

    // Writing off is not paying: ChargeBalance deliberately does not subtract
    // it, so the balance itself is unmoved (design section 4).
    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('620.000');

    // The student's history still shows it — queried by the owning student,
    // not merely by the charge's own id, the row is still there in full.
    $stillInHistory = Charge::query()
        ->whereHas('enrollment', fn ($query) => $query->where('student_id', $studentId))
        ->find($charge->getKey());

    expect($stillInHistory)->not->toBeNull()
        ->and($stillInHistory->amount)->toBe('620.000');
});

/*
|--------------------------------------------------------------------------
| A second write-off is refused, not silently re-stamped
|--------------------------------------------------------------------------
*/

it('refuses to write off an already written-off charge, leaving the original decision untouched', function () {
    $this->travelTo('2026-08-01 09:00:00');

    $charge = Charge::factory()->create(['amount' => '500.000']);

    $first = $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'First decision: the centre will not collect this.',
    ));

    $originalAt = $first->written_off_at;
    $originalBy = (int) $first->written_off_by;
    $originalReason = $first->written_off_reason;

    // Time passes, and a different super admin tries a second time.
    $this->travelTo('2026-08-05 09:00:00');
    $secondSuperAdmin = ($this->actorWith)('super_admin');

    $thrown = null;

    try {
        $this->writeOff->execute($secondSuperAdmin, new WriteOffChargeData(
            (int) $charge->getKey(),
            'A second, later attempt at the same charge.',
        ));
    } catch (ChargeAlreadyWrittenOffException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAlreadyWrittenOffException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey());

    $fresh = $charge->fresh();

    expect($fresh->written_off_at->equalTo($originalAt))->toBeTrue()
        ->and((int) $fresh->written_off_by)->toBe($originalBy)
        ->and($fresh->written_off_reason)->toBe($originalReason)
        ->and((int) $fresh->written_off_by)->not->toBe((int) $secondSuperAdmin->getKey());
});

/*
|--------------------------------------------------------------------------
| An admin cannot write off, invoked directly
|--------------------------------------------------------------------------
*/

it('refuses an admin, even holding every seeded charge read permission', function () {
    $charge = Charge::factory()->create();

    expect($this->admin->can('view_any_charge'))->toBeTrue()
        ->and($this->admin->can('view_charge'))->toBeTrue();

    $thrown = null;

    try {
        $this->writeOff->execute($this->admin, new WriteOffChargeData(
            (int) $charge->getKey(),
            'An admin attempting a write-off they do not hold write_off_charge for.',
        ));
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($charge->fresh()->isWrittenOff())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| create(), update() and delete() refuse even when the permission is granted
|--------------------------------------------------------------------------
|
| THE ASSERTION THAT PROVES THE POLICY IGNORES THE GRANT (ActivityAppendOnlyTest
| pattern). Production never seeds create_charge, update_charge or
| delete_charge — design section 4 — but a permission that does not exist
| proves nothing about a policy: the refusal could just as easily be "nobody
| holds it". So the permissions are created HERE, granted to the strongest
| actor there is, and the refusal asserted anyway.
*/

it('refuses create, update and delete for a super admin even when the permission is granted', function () {
    $charge = Charge::factory()->create();

    foreach (['create_charge', 'update_charge', 'delete_charge'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->superAdmin->givePermissionTo(['create_charge', 'update_charge', 'delete_charge']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdmin = $this->superAdmin->refresh();

    expect($superAdmin->can('create_charge'))->toBeTrue()
        ->and($superAdmin->can('update_charge'))->toBeTrue()
        ->and($superAdmin->can('delete_charge'))->toBeTrue();

    expect(Gate::forUser($superAdmin)->allows('create', Charge::class))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('update', $charge))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $charge))->toBeFalse();
});
