<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Exceptions\RoleEscalationException;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Escalation paths that route *around* the three headline guards.
 *
 * EscalationGuardTest covers the three guards as specified. This file covers
 * the ways to reach the same end state without tripping them.
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

/*
|--------------------------------------------------------------------------
| Path 1: strip the role instead of deleting the account
|--------------------------------------------------------------------------
|
| Guard 3 blocks delete and deactivate. Removing the super_admin role reaches
| the identical end state — zero accounts able to manage roles — and the three
| specified guards say nothing about it.
*/

it('refuses to remove the super_admin role from the last active super admin', function () {
    $this->superAdmin->removeRole('super_admin');
})->throws(LastSuperAdminException::class);

it('refuses to sync away the super_admin role from the last active super admin', function () {
    $this->superAdmin->syncRoles([]);
})->throws(LastSuperAdminException::class);

it('refuses to sync the last super admin down to a lesser role', function () {
    $this->superAdmin->syncRoles(['admin']);
})->throws(LastSuperAdminException::class);

it('allows removing the super_admin role when another active super admin exists', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->superAdmin->removeRole('super_admin');

    expect($this->superAdmin->fresh()->hasRole('super_admin'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Path 2: force delete and soft-deleted survivors
|--------------------------------------------------------------------------
*/

it('applies guard 3 to forceDelete as well as delete', function () {
    $this->superAdmin->forceDelete();
})->throws(LastSuperAdminException::class);

/*
 * The regression that matters. Spatie's own "deleting" listener detaches every
 * role when force deleting, and it runs before ours, so a guard that reads
 * hasRole() at "deleting" time sees an account with no roles and lets it
 * through. Re-fetching guarantees the roles relation is cold, which is the case
 * that actually failed — with a warm relation hasRole() reads the stale
 * in-memory copy and the guard appears to work.
 */
it('applies guard 3 to forceDelete when the roles relation is not loaded', function () {
    $cold = User::findOrFail($this->superAdmin->getKey());

    expect($cold->relationLoaded('roles'))->toBeFalse();

    $cold->forceDelete();
})->throws(LastSuperAdminException::class);

it('leaves the account intact after a blocked force delete', function () {
    $cold = User::findOrFail($this->superAdmin->getKey());

    try {
        $cold->forceDelete();
    } catch (LastSuperAdminException) {
        // expected
    }

    expect(User::withTrashed()->whereKey($this->superAdmin->getKey())->exists())->toBeTrue()
        ->and($this->superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('does not count a soft-deleted super admin as a survivor', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');
    $second->delete();

    $this->superAdmin->delete();
})->throws(LastSuperAdminException::class);

/*
|--------------------------------------------------------------------------
| Path 3: write the roles relation directly, never asking the policy
|--------------------------------------------------------------------------
|
| UserPolicy::assignRole() is only consulted by code that chooses to consult
| it. Spatie's assignRole() answers to no gate, so a Filament form, a custom
| action or a controller could grant super_admin without the policy ever
| running. These assert the model layer refuses regardless.
*/

it('forbids an authenticated admin from granting super_admin directly', function () {
    $this->actingAs($this->admin);

    $this->staff->assignRole('super_admin');
})->throws(RoleEscalationException::class);

it('forbids an authenticated admin from granting super_admin via syncRoles', function () {
    $this->actingAs($this->admin);

    $this->staff->syncRoles(['staff', 'super_admin']);
})->throws(RoleEscalationException::class);

it('forbids an authenticated admin from stripping the super_admin role', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->actingAs($this->admin);

    $second->removeRole('super_admin');
})->throws(RoleEscalationException::class);

it('allows an authenticated super admin to grant super_admin to someone else', function () {
    $this->actingAs($this->superAdmin);

    $this->staff->assignRole('super_admin');

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Path 4: promote yourself
|--------------------------------------------------------------------------
*/

it('forbids an authenticated user from adding a role to themselves', function () {
    $this->actingAs($this->admin);

    $this->admin->assignRole('super_admin');
})->throws(RoleEscalationException::class);

it('forbids even a super admin from changing their own roles at the model layer', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->actingAs($this->superAdmin);

    $this->superAdmin->assignRole('admin');
})->throws(RoleEscalationException::class);

it('permits a self-save that does not actually change roles', function () {
    $this->actingAs($this->admin);

    $this->admin->syncRoles(['admin']);

    expect($this->admin->fresh()->hasRole('admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Path 5: direct permission grants, bypassing roles entirely
|--------------------------------------------------------------------------
|
| Guard 2 in the spec reads "roles or permissions". givePermissionTo() writes
| the model_has_permissions pivot and never consults a role.
*/

it('forbids an authenticated user from granting themselves a permission', function () {
    $this->actingAs($this->staff);

    $this->staff->givePermissionTo('delete_user');
})->throws(RoleEscalationException::class);

it('forbids an authenticated user from syncing their own permissions', function () {
    $this->actingAs($this->admin);

    $this->admin->syncPermissions(['assign_role']);
})->throws(RoleEscalationException::class);

it('does not let a directly granted permission confer super admin rank', function () {
    $impostor = User::factory()->create();
    $impostor->givePermissionTo('update_user', 'delete_user');

    expect($impostor->can('update', $this->superAdmin))->toBeFalse()
        ->and($impostor->can('delete', $this->superAdmin))->toBeFalse()
        ->and($impostor->can('update', $this->staff))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Path 6: smuggle roles in through mass assignment
|--------------------------------------------------------------------------
|
| Safe today only because 'roles' is absent from the Fillable attribute and is
| a relation rather than a column, so fill() drops it. That is a one-word edit
| away from being untrue, hence the test.
*/

it('ignores a roles array passed to mass assignment', function () {
    $this->staff->update(['roles' => ['super_admin'], 'name' => 'Renamed']);

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($this->staff->fresh()->name)->toBe('Renamed');
});

/*
|--------------------------------------------------------------------------
| Path 7: holding two roles at once
|--------------------------------------------------------------------------
*/

it('treats a user holding both admin and super_admin as a super admin', function () {
    $dual = User::factory()->create();
    $dual->assignRole('admin', 'super_admin');

    // Protected as a target...
    expect($this->admin->can('update', $dual))->toBeFalse()
        // ...and privileged as an actor.
        ->and($dual->can('update', $this->superAdmin))->toBeTrue()
        ->and($dual->can('assignRole', [User::class, 'super_admin']))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Path 8: unauthenticated contexts
|--------------------------------------------------------------------------
|
| Documents a deliberate boundary. The actor-dependent guards need a principal
| to authorize against; seeders, queued jobs and console commands have none, so
| they are skipped there. Guard 3 is a system invariant and still applies.
*/

it('permits role assignment when no actor is authenticated', function () {
    expect(auth()->check())->toBeFalse();

    $this->staff->assignRole('super_admin');

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('still enforces guard 3 when no actor is authenticated', function () {
    expect(auth()->check())->toBeFalse();

    $this->superAdmin->removeRole('super_admin');
})->throws(LastSuperAdminException::class);

/*
|--------------------------------------------------------------------------
| Path 9: the Shield super-admin gate bypass
|--------------------------------------------------------------------------
|
| Filament Shield can define super_admin via Gate::before, which short-circuits
| every policy and returns true for any ability. That would silently defeat
| guard 2 — a super admin would be allowed to edit their own roles, because
| UserPolicy::modifyOwnRoles() would never run. The seeder grants super_admin
| every permission explicitly, so the gate is not needed. This asserts nobody
| turns it on without also revisiting these guards.
*/

it('keeps the Shield super admin gate interception disabled', function () {
    expect(config('filament-shield.super_admin.define_via_gate'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Policy surface not covered by the headline twelve
|--------------------------------------------------------------------------
*/

it('forbids an admin from resetting a super admin password', function () {
    expect($this->admin->can('resetPassword', $this->superAdmin))->toBeFalse()
        ->and($this->admin->can('resetPassword', $this->staff))->toBeTrue();
});

it('forbids staff from any user management', function () {
    expect($this->staff->can('viewAny', User::class))->toBeFalse()
        ->and($this->staff->can('update', $this->admin))->toBeFalse()
        ->and($this->staff->can('delete', $this->admin))->toBeFalse()
        ->and($this->staff->can('assignRole', [User::class, 'staff']))->toBeFalse();
});
