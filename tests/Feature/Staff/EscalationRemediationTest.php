<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Regression cover for P1-T04b — the seven confirmed privilege-escalation
 * bypasses found after Task 4. Each block names the finding it pins.
 *
 * These assert on outcomes (did the bypass reach its end state?) rather than on
 * exception types, so the same test runs unmodified against pre-fix `main` — it
 * fails there because the bypass succeeds — and against the remediated tree,
 * where the guard blocks it. Finding 3 (the safe Filament pattern) is a new
 * capability, not an outcome, and is covered in SafeRoleSyncPatternTest.
 *
 * The role model is resolved through config so the tests exercise whatever
 * class the app has registered (Spatie's unprotected Role on main, the
 * protected App\Models\Role after the fix) without hard-coding either.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    /** @var class-string<Role> */
    $this->roleModel = config('permission.models.role');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->staff = User::factory()->create();
    $this->staff->assignRole('staff');
});

/*
|--------------------------------------------------------------------------
| Finding 1 — the super_admin role row is unprotected
|--------------------------------------------------------------------------
*/

it('finding 1: refuses to delete the super_admin role', function () {
    $role = $this->roleModel::where('name', 'super_admin')->firstOrFail();

    try {
        $role->delete();
    } catch (Throwable) {
        // A protected role throws; an unprotected one deletes silently.
    }

    expect($this->roleModel::where('name', 'super_admin')->exists())->toBeTrue();
});

it('finding 1: refuses to rename the super_admin role', function () {
    $role = $this->roleModel::where('name', 'super_admin')->firstOrFail();
    $role->name = 'renamed_super_admin';

    try {
        $role->save();
    } catch (Throwable) {
    }

    expect($this->roleModel::where('name', 'super_admin')->exists())->toBeTrue()
        ->and($this->roleModel::where('name', 'renamed_super_admin')->exists())->toBeFalse();
});

it('finding 1: refuses to strip the last super admin via the role-side pivot helper', function () {
    // Spatie's HasAssignedModels::removeFromModels() detaches straight from the
    // model_has_roles pivot, never touching User::removeRole().
    $role = $this->roleModel::where('name', 'super_admin')->firstOrFail();

    try {
        $role->removeFromModels($this->superAdmin);
    } catch (Throwable) {
    }

    expect($this->superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Finding 2 — Shield's role editor can strip super_admin's permissions
|--------------------------------------------------------------------------
*/

it('finding 2: keeps super_admin permissions when the role editor syncs a reduced set', function () {
    $role = $this->roleModel::where('name', 'super_admin')->firstOrFail();
    $total = Permission::count();

    // Shield's EditRole::afterSave() calls syncPermissions() with only the
    // permissions surfaced in its UI; custom ones are hidden, so a plain save
    // would silently drop them.
    try {
        $role->syncPermissions(['view_any_user']);
    } catch (Throwable) {
    }

    expect($role->fresh()->permissions()->count())->toBe($total);
});

/*
|--------------------------------------------------------------------------
| Finding 4 — model-layer role/permission writers ignore assign_role
|--------------------------------------------------------------------------
*/

it('finding 4: forbids an authenticated staff user from assigning a role to another account', function () {
    $target = User::factory()->create();

    $this->actingAs($this->staff); // staff does not hold assign_role

    try {
        $target->assignRole('admin');
    } catch (Throwable) {
    }

    expect($target->fresh()->hasRole('admin'))->toBeFalse();
});

it('finding 4: forbids an authenticated staff user from granting a permission to another account', function () {
    $target = User::factory()->create();

    $this->actingAs($this->staff);

    try {
        $target->givePermissionTo('assign_role');
    } catch (Throwable) {
    }

    expect($target->fresh()->hasPermissionTo('assign_role'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 5 — guard 1 not enforced on direct Eloquent writes
|--------------------------------------------------------------------------
*/

it('finding 5: forbids an authenticated admin from updating a super admin directly', function () {
    $survivor = User::factory()->create();
    $survivor->assignRole('super_admin'); // isolate from guard 3

    $this->actingAs($this->admin);

    try {
        $this->superAdmin->update(['name' => 'Hijacked']);
    } catch (Throwable) {
    }

    expect($this->superAdmin->fresh()->name)->not->toBe('Hijacked');
});

it('finding 5: forbids an authenticated admin from soft-deleting a super admin directly', function () {
    $survivor = User::factory()->create();
    $survivor->assignRole('super_admin');

    $this->actingAs($this->admin);

    try {
        $this->superAdmin->delete();
    } catch (Throwable) {
    }

    expect(User::find($this->superAdmin->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Finding 6 — guard 3 trusts stale and mutable role state
|--------------------------------------------------------------------------
*/

it('finding 6: applies guard 3 even when a stale roles relation hides the role', function () {
    // A loaded-but-empty roles relation made hasRole('super_admin') answer
    // false, so the guard returned early and the last super admin was deleted.
    $this->superAdmin->setRelation('roles', collect());

    try {
        $this->superAdmin->delete();
    } catch (Throwable) {
    }

    expect(User::find($this->superAdmin->id))->not->toBeNull();
});

it('finding 6: applies guard 3 when a Role object name is mutated in memory', function () {
    // Spatie detaches by primary key; a guard that read the object's name could
    // be desynced by mutating the unsaved name.
    $role = $this->roleModel::where('name', 'super_admin')->firstOrFail();
    $role->name = 'not_super_admin';

    try {
        $this->superAdmin->removeRole($role);
    } catch (Throwable) {
    }

    expect($this->superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Finding 7 — guard 3 is neither atomic nor concurrency-safe
|--------------------------------------------------------------------------
*/

it('finding 7: takes a pessimistic lock while checking the last super admin', function () {
    $survivor = User::factory()->create();
    $survivor->assignRole('super_admin');

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $this->superAdmin->delete();

    $queries = collect(DB::connection()->getQueryLog())
        ->pluck('query')
        ->map(fn (string $q): string => strtolower($q));

    DB::connection()->disableQueryLog();

    expect($queries->contains(fn (string $q): bool => str_contains($q, 'for update')))->toBeTrue();
});
