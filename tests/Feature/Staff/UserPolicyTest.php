<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UserPolicy is the authorization boundary behind escalation guards 1, 2, and 4.
 * The request-path Actions authorize through it via Gate::forUser($actor), so
 * these policy assertions are the contract those Actions rely on. Every check is
 * asserted both ways — what an actor may do and what it may not.
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
});

// Guard 1: an admin cannot act on a super admin.

/**
 * The persisted Role row for a name.
 *
 * UserPolicy::assignRole() takes a resolved Role rather than a request string
 * since P1-T15: the database matches names case-insensitively and PHP does not,
 * so a string comparison let 'Super_Admin' through as "not the super-admin
 * role". Tests resolve the row the same way the Action does.
 */
function role(string $name): Role
{
    return Role::query()->where('name', $name)->firstOrFail();
}

it('forbids an admin from updating a super admin', function () {
    expect($this->admin->can('update', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from deleting a super admin', function () {
    expect($this->admin->can('delete', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from assigning the super_admin role', function () {
    expect($this->admin->can('assignRole', [User::class, role('super_admin')]))->toBeFalse();
});

it('forbids an admin from resetting a super admin password', function () {
    expect($this->admin->can('resetPassword', $this->superAdmin))->toBeFalse()
        ->and($this->admin->can('resetPassword', $this->staff))->toBeTrue();
});

it('allows an admin to update a staff user', function () {
    expect($this->admin->can('update', $this->staff))->toBeTrue();
});

it('allows a super admin to update another super admin', function () {
    $other = User::factory()->create();
    $other->assignRole('super_admin');

    expect($this->superAdmin->can('update', $other))->toBeTrue();
});

// Guard 2: nobody manages their own account roles or deletes themselves.
it('allows a super admin to assign the admin role generally', function () {
    expect($this->superAdmin->can('assignRole', [User::class, role('admin')]))->toBeTrue();
});

it('forbids a super admin from changing their own roles', function () {
    expect($this->superAdmin->can('modifyOwnRoles', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from deleting their own account', function () {
    expect($this->admin->can('delete', $this->admin))->toBeFalse();
});

// Guard 4: only holders of assign_role may manage roles. Admins hold it
// (P1-T05b) so they can onboard staff — an admin who could create an account
// but never give it a role would only ever produce unreachable accounts.
it('forbids an actor without assign_role from assigning even a lesser role', function () {
    expect($this->staff->can('assignRole', [User::class, role('staff')]))->toBeFalse();
});

it('lets an admin assign a lesser role but never super_admin', function () {
    // Guard 1, not the absence of assign_role, is the boundary for admins.
    expect($this->admin->can('assignRole', [User::class, role('staff')]))->toBeTrue()
        ->and($this->admin->can('assignRole', [User::class, role('super_admin')]))->toBeFalse();
});

// Rank is resolved by role, never by a directly granted permission.
it('does not let a directly granted permission confer super admin rank', function () {
    $impostor = User::factory()->create();
    $impostor->givePermissionTo('update_user', 'delete_user');

    expect($impostor->can('update', $this->superAdmin))->toBeFalse()
        ->and($impostor->can('delete', $this->superAdmin))->toBeFalse()
        ->and($impostor->can('update', $this->staff))->toBeTrue();
});

it('treats a user holding both admin and super_admin as a super admin', function () {
    $dual = User::factory()->create();
    $dual->assignRole('admin', 'super_admin');

    // Protected as a target...
    expect($this->admin->can('update', $dual))->toBeFalse()
        // ...and privileged as an actor.
        ->and($dual->can('update', $this->superAdmin))->toBeTrue()
        ->and($dual->can('assignRole', [User::class, role('super_admin')]))->toBeTrue();
});

it('forbids staff from any user management', function () {
    expect($this->staff->can('viewAny', User::class))->toBeFalse()
        ->and($this->staff->can('update', $this->admin))->toBeFalse()
        ->and($this->staff->can('delete', $this->admin))->toBeFalse()
        ->and($this->staff->can('assignRole', [User::class, role('staff')]))->toBeFalse();
});

it('resolves super admin rank fresh, ignoring a stale in-memory roles relation', function () {
    // A loaded-but-empty roles relation must not fool the rank check: isSuperAdmin()
    // queries the pivot by immutable id, not the cached relation.
    $this->superAdmin->setRelation('roles', collect());

    expect($this->superAdmin->isSuperAdmin())->toBeTrue()
        ->and($this->admin->can('update', $this->superAdmin))->toBeFalse();
});

it('ignores a roles array smuggled through mass assignment', function () {
    // 'roles' is neither fillable nor a column, so fill() drops it. One-word edit
    // from being untrue, hence the guard.
    $this->staff->update(['roles' => ['super_admin'], 'name' => 'Renamed']);

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($this->staff->fresh()->name)->toBe('Renamed');
});

it('keeps the Shield super admin gate interception disabled', function () {
    // define_via_gate=true would make Gate::before short-circuit every policy,
    // silently defeating guard 2. The seeder grants super_admin every permission
    // explicitly, so the gate is not needed.
    expect(config('filament-shield.super_admin.define_via_gate'))->toBeFalse();
});
