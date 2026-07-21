<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/**
 * Regression cover for the P1-T04d review findings.
 *
 * Each of these was confirmed exploitable with a rolled-back probe against
 * P1-T04c before the fix landed.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->superAdmin = User::factory()->create();
    $this->system->assignRoles($this->superAdmin, 'super_admin');
});

/*
|--------------------------------------------------------------------------
| Finding 1 — bulk deletion bypassed the per-record super_admin protection
|--------------------------------------------------------------------------
| Filament authorizes DeleteBulkAction once against deleteAny() and never
| consults delete() for the selected records, so bulk delete could remove the
| super_admin role that delete() refuses to touch.
*/

it('refuses bulk role deletion outright, even for a super admin', function () {
    expect(Gate::forUser($this->superAdmin)->allows('deleteAny', Role::class))->toBeFalse();
});

it('refuses bulk force deletion and bulk restore of roles', function () {
    expect(Gate::forUser($this->superAdmin)->allows('forceDeleteAny', Role::class))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows('restoreAny', Role::class))->toBeFalse();
});

it('still refuses deleting the super_admin role individually', function () {
    $superAdminRole = Role::where('name', Role::SUPER_ADMIN)->firstOrFail();

    expect(Gate::forUser($this->superAdmin)->allows('delete', $superAdminRole))->toBeFalse();

    // And the row is untouched.
    expect(Role::where('name', Role::SUPER_ADMIN)->exists())->toBeTrue();
});

it('refuses replicating the super_admin role, which would clone an unprotected anchor', function () {
    $superAdminRole = Role::where('name', Role::SUPER_ADMIN)->firstOrFail();

    expect(Gate::forUser($this->superAdmin)->allows('replicate', $superAdminRole))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 2 — guard 4 was missing from role and permission writes
|--------------------------------------------------------------------------
| An actor holding update_role but not assign_role could change another
| role's permissions.
*/

it('refuses a role permission change by an actor without assign_role', function () {
    $actor = User::factory()->create();
    $limited = Role::findOrCreate('limited_role_editor', 'web');
    $this->system->syncRolePermissions($limited, ['update_role']);
    $this->system->assignRoles($actor, 'limited_role_editor');

    $target = Role::where('name', 'staff')->firstOrFail();
    $before = $target->permissions()->pluck('name')->sort()->values()->all();

    expect(fn () => app(UpdateRolePermissionsAction::class)
        ->execute($actor, $target, ['view_any_student']))
        ->toThrow(AuthorizationException::class);

    $after = $target->fresh()->permissions()->pluck('name')->sort()->values()->all();
    expect($after)->toBe($before);
});

/**
 * Guard 4 must hold for EVERY role mutation.
 *
 * The first pass added assign_role to create() and update() only; a probe then
 * deleted the staff role with delete_role alone, and the tests stayed green
 * because they only covered the two abilities that had been fixed. This case
 * enumerates every mutating ability so an omission cannot hide again.
 */
it('refuses every role mutation to an actor holding the operation permission but not assign_role', function (
    string $ability,
    string $permission,
    bool $needsRecord,
) {
    $actor = User::factory()->create();
    $limited = Role::findOrCreate('limited_role_editor', 'web');
    $this->system->syncRolePermissions($limited, [$permission]);
    $this->system->assignRoles($actor, 'limited_role_editor');

    $target = Role::where('name', 'staff')->firstOrFail();
    $subject = $needsRecord ? $target : Role::class;

    expect(Gate::forUser($actor)->allows($ability, $subject))->toBeFalse(
        "{$ability} must require assign_role, not just {$permission}",
    );

    // The role is still there — a denied check must not have side effects.
    expect(Role::where('name', 'staff')->exists())->toBeTrue();
})->with([
    'create' => ['create', 'create_role', false],
    'update' => ['update', 'update_role', true],
    'delete' => ['delete', 'delete_role', true],
    'forceDelete' => ['forceDelete', 'force_delete_role', true],
    'restore' => ['restore', 'restore_role', true],
    'replicate' => ['replicate', 'replicate_role', true],
    'reorder' => ['reorder', 'reorder_role', false],
]);

it('still allows every role mutation to an actor who does hold assign_role', function () {
    // The mirror of the above: guard 4 must not have become a blanket refusal.
    $actor = User::factory()->create();
    $manager = Role::findOrCreate('full_role_manager', 'web');
    $this->system->syncRolePermissions($manager, [
        'create_role', 'update_role', 'delete_role', 'force_delete_role',
        'restore_role', 'replicate_role', 'reorder_role', 'assign_role',
    ]);
    $this->system->assignRoles($actor, 'full_role_manager');

    $target = Role::where('name', 'staff')->firstOrFail();

    expect(Gate::forUser($actor)->allows('create', Role::class))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('reorder', Role::class))->toBeTrue();
});

it('still lets a super admin manage a role they do not hold', function () {
    $target = Role::where('name', 'staff')->firstOrFail();

    app(UpdateRolePermissionsAction::class)
        ->execute($this->superAdmin, $target, ['view_any_student']);

    expect($target->fresh()->permissions()->pluck('name')->all())->toBe(['view_any_student']);
});

/*
|--------------------------------------------------------------------------
| Finding 3 — the trusted system writer could remove the final super admin
|--------------------------------------------------------------------------
| Guards 1, 2 and 4 are actor-relative and genuinely exempt on the system
| path. Guard 3 is a system invariant and is not.
*/

it('refuses to strip the super_admin role from the last super admin, even on the system path', function () {
    expect(fn () => $this->system->syncRoles($this->superAdmin, []))
        ->toThrow(LastSuperAdminException::class);

    expect($this->superAdmin->fresh()->isSuperAdmin())->toBeTrue()
        ->and(User::role(Role::SUPER_ADMIN)->where('is_active', true)->count())->toBe(1);
});

it('allows the system path to strip super_admin when another active one remains', function () {
    $second = User::factory()->create();
    $this->system->assignRoles($second, 'super_admin');

    $this->system->syncRoles($this->superAdmin, ['admin']);

    expect($this->superAdmin->fresh()->isSuperAdmin())->toBeFalse()
        ->and(User::role(Role::SUPER_ADMIN)->where('is_active', true)->count())->toBe(1);
});

it('does not engage the invariant for a user who is not a super admin', function () {
    // The invariant must fire only when a write actually removes the role.
    // Seeders and factories constantly sync ordinary users' roles, and a rule
    // about the *last* super admin must never block that.
    $ordinary = User::factory()->create();
    $this->system->assignRoles($ordinary, 'staff');

    $this->system->syncRoles($ordinary, []);

    expect($ordinary->fresh()->roles()->pluck('name')->all())->toBe([])
        ->and(User::role(Role::SUPER_ADMIN)->where('is_active', true)->count())->toBe(1);
});

it('lets the system path seed a super admin into an empty database', function () {
    // Bootstrapping a fresh install: granting the role must never be blocked by
    // the rule that protects removing it.
    User::query()->forceDelete();
    expect(User::role(Role::SUPER_ADMIN)->count())->toBe(0);

    $first = User::factory()->create();
    $this->system->assignRoles($first, 'super_admin');

    expect($first->fresh()->isSuperAdmin())->toBeTrue();
});
