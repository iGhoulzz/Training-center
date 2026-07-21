<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RolePolicy is the binding request-path boundary for role mutation now that the
 * Role model holds no write guards. It denies:
 *   - editing, deleting, or force-deleting the canonical super_admin role, and
 *   - editing or deleting any role the acting user currently holds,
 * on top of the seeded role-CRUD permissions (which are super-admin-only).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    $this->staffRole = Role::where('name', 'staff')->firstOrFail();
});

it('forbids everyone, including a super admin, from deleting the super_admin role', function () {
    expect($this->superAdmin->can('delete', $this->superAdminRole))->toBeFalse()
        ->and($this->superAdmin->can('forceDelete', $this->superAdminRole))->toBeFalse();
});

it('forbids everyone, including a super admin, from editing the super_admin role', function () {
    expect($this->superAdmin->can('update', $this->superAdminRole))->toBeFalse();
});

it('allows a super admin to edit and delete a role they do not hold', function () {
    expect($this->superAdmin->can('update', $this->staffRole))->toBeTrue()
        ->and($this->superAdmin->can('delete', $this->staffRole))->toBeTrue();
});

it('forbids an actor from editing or deleting a role they hold', function () {
    // A bespoke role that carries the role-management permissions, so the only
    // thing standing between the holder and editing it is the held-role guard.
    // assign_role is included deliberately: guard 4 (P1-T04d) requires it for
    // any role write, and without it the final assertion below would pass for
    // the wrong reason — proving guard 4 rather than the held-role rule.
    $manager = Role::findOrCreate('manager', 'web');
    $manager->syncPermissions([
        'update_role', 'delete_role', 'view_role', 'view_any_role', 'assign_role',
    ]);

    $holder = User::factory()->create();
    $holder->assignRole('manager');

    expect($holder->can('update', $manager))->toBeFalse('an actor must not edit a role they hold')
        ->and($holder->can('delete', $manager))->toBeFalse('an actor must not delete a role they hold')
        // ...but the same actor may manage a role they do not hold.
        ->and($holder->can('update', $this->staffRole))->toBeTrue();
});

it('forbids an admin from managing roles at all (no role permissions)', function () {
    expect($this->admin->can('viewAny', Role::class))->toBeFalse()
        ->and($this->admin->can('update', $this->staffRole))->toBeFalse()
        ->and($this->admin->can('delete', $this->staffRole))->toBeFalse();
});
