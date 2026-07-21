<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\DeactivateUserAction;
use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The request-path write boundary. Every security-sensitive role/permission/user
 * mutation goes through an Action that receives the actor explicitly and
 * authorizes via the gate. These tests exercise the Actions directly — no model
 * override is left to lean on.
 *
 * Authorization refusals surface as Illuminate\Auth\Access\AuthorizationException
 * (guards 1/2/4 → 403); the last-super-admin business rule surfaces as
 * LastSuperAdminException (guard 3 → 422). Nothing catches a generic Throwable.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->staff = User::factory()->create();
    $this->staff->assignRole('staff');

    $this->sync = app(SyncUserRolesAction::class);
    $this->deleteUser = app(DeleteUserAction::class);
    $this->deactivate = app(DeactivateUserAction::class);
    $this->updatePermissions = app(UpdateRolePermissionsAction::class);
});

/*
|--------------------------------------------------------------------------
| SyncUserRolesAction — role assignment guards
|--------------------------------------------------------------------------
*/

it('forbids staff from assigning super_admin to another user', function () {
    $target = User::factory()->create();

    expect(fn () => $this->sync->execute($this->staff, $target, ['super_admin']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('forbids staff from self-promotion', function () {
    expect(fn () => $this->sync->execute($this->staff, $this->staff, ['staff', 'super_admin']))
        ->toThrow(AuthorizationException::class);

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('forbids an admin from self-promotion (guard 2)', function () {
    expect(fn () => $this->sync->execute($this->admin, $this->admin, ['admin', 'super_admin']))
        ->toThrow(AuthorizationException::class);

    expect($this->admin->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('forbids an admin from granting super_admin (guards 4 and 1)', function () {
    $target = User::factory()->create();

    expect(fn () => $this->sync->execute($this->admin, $target, ['super_admin']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('forbids an admin from assigning even a lesser role (guard 4)', function () {
    $target = User::factory()->create();

    expect(fn () => $this->sync->execute($this->admin, $target, ['staff']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->hasRole('staff'))->toBeFalse();
});

it('forbids an admin from removing super_admin from one of two super admins (guard 1 rank refusal, not last-admin)', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    // Two super admins exist, so this is a rank refusal — NOT the last-admin rule.
    expect(fn () => $this->sync->execute($this->admin, $second, []))
        ->toThrow(AuthorizationException::class);

    expect($second->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('allows a no-op self-sync that changes nothing', function () {
    $this->sync->execute($this->admin, $this->admin, ['admin']);

    expect($this->admin->fresh()->hasRole('admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SyncUserRolesAction — legitimate super-admin actions (controls)
|--------------------------------------------------------------------------
*/

it('lets a super admin grant super_admin to someone else', function () {
    $target = User::factory()->create();

    $this->sync->execute($this->superAdmin, $target, ['super_admin']);

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('lets a super admin set a lesser role on another account', function () {
    $target = User::factory()->create();
    $target->assignRole('staff');

    $this->sync->execute($this->superAdmin, $target, ['admin']);

    expect($target->fresh()->hasRole('admin'))->toBeTrue()
        ->and($target->fresh()->hasRole('staff'))->toBeFalse();
});

it('lets a super admin strip super_admin from another when survivors remain', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->sync->execute($this->superAdmin, $second, ['admin']);

    expect($second->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($second->fresh()->hasRole('admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| DeleteUserAction
|--------------------------------------------------------------------------
*/

it('rejects a direct self-delete and leaves the account intact', function () {
    expect(fn () => $this->deleteUser->execute($this->admin, $this->admin))
        ->toThrow(AuthorizationException::class);

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('forbids an admin from deleting a super admin (guard 1)', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    expect(fn () => $this->deleteUser->execute($this->admin, $this->superAdmin))
        ->toThrow(AuthorizationException::class);

    expect(User::find($this->superAdmin->id))->not->toBeNull();
});

it('lets a super admin delete another super admin when survivors remain', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->deleteUser->execute($this->superAdmin, $second);

    expect(User::find($second->id))->toBeNull();
});

it('lets a super admin delete an ordinary account', function () {
    $this->deleteUser->execute($this->superAdmin, $this->staff);

    expect(User::find($this->staff->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| DeactivateUserAction — the reachable last-super-admin path
|--------------------------------------------------------------------------
*/

it('refuses to deactivate the last active super admin (guard 3, 422 business rule)', function () {
    // A super admin deactivating their own account when nobody else can manage
    // roles is the one request-path route to zero super admins.
    expect(fn () => $this->deactivate->execute($this->superAdmin, $this->superAdmin))
        ->toThrow(LastSuperAdminException::class);

    expect($this->superAdmin->fresh()->is_active)->toBeTrue();
});

it('allows deactivating a super admin when another active one remains', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->deactivate->execute($this->superAdmin, $this->superAdmin);

    expect($this->superAdmin->fresh()->is_active)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| UpdateRolePermissionsAction
|--------------------------------------------------------------------------
*/

it('forbids modifying the permissions of a role the actor holds', function () {
    $manager = Role::findOrCreate('manager', 'web');
    $manager->syncPermissions(['update_role', 'view_role', 'view_any_role']);

    $holder = User::factory()->create();
    $holder->assignRole('manager');

    expect(fn () => $this->updatePermissions->execute($holder, $manager, ['update_role', 'delete_role']))
        ->toThrow(AuthorizationException::class);

    expect($manager->fresh()->hasPermissionTo('delete_role'))->toBeFalse();
});

it('forbids an unauthorized actor from altering another role permissions', function () {
    // Admin holds no role-management permissions.
    $staffRole = Role::where('name', 'staff')->firstOrFail();

    expect(fn () => $this->updatePermissions->execute($this->admin, $staffRole, ['view_any_user']))
        ->toThrow(AuthorizationException::class);

    expect($staffRole->fresh()->hasPermissionTo('view_any_user'))->toBeFalse();
});

it('forbids editing the super_admin role permissions and keeps its full set', function () {
    $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    $total = $superAdminRole->permissions()->count();

    expect(fn () => $this->updatePermissions->execute($this->superAdmin, $superAdminRole, ['view_any_user']))
        ->toThrow(AuthorizationException::class);

    expect($superAdminRole->fresh()->permissions()->count())->toBe($total);
});

it('lets a super admin set the permissions of an ordinary role', function () {
    $staffRole = Role::where('name', 'staff')->firstOrFail();

    $this->updatePermissions->execute($this->superAdmin, $staffRole, ['view_any_student', 'view_student']);

    expect($staffRole->fresh()->getPermissionNames()->sort()->values()->all())
        ->toBe(['view_any_student', 'view_student']);
});

/*
|--------------------------------------------------------------------------
| Actorless invocation vs. the trusted system path
|--------------------------------------------------------------------------
*/

it('rejects an actorless request-path Action invocation at the type boundary', function () {
    $target = User::factory()->create();

    // The Actions take a non-nullable User $actor; there is no way to call them
    // without a principal.
    expect(fn () => $this->sync->execute(null, $target, ['admin'])) // @phpstan-ignore-line
        ->toThrow(TypeError::class);

    expect($target->fresh()->hasRole('admin'))->toBeFalse();
});

it('lets the trusted system path assign roles with no actor', function () {
    expect(auth()->check())->toBeFalse();

    $target = User::factory()->create();

    app(SystemRoleWriter::class)->assignRoles($target, 'super_admin');

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});
