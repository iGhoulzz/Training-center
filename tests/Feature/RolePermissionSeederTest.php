<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates the four roles', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::pluck('name')->all())
        ->toContain('super_admin', 'admin', 'staff', 'student');
});

it('gives super_admin every permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $superAdmin = Role::findByName('super_admin');
    $total = Permission::count();

    expect($superAdmin->permissions)->toHaveCount($total);
});

it('denies staff any user-management permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $staff = Role::findByName('staff');

    expect($staff->hasPermissionTo('create_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('update_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('delete_user'))->toBeFalse()
        ->and($staff->hasPermissionTo('view_any_activity'))->toBeFalse();
});

it('denies admin the ability to manage roles', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('admin')->hasPermissionTo('update_role'))->toBeFalse();
});

it('grants admin panel access to staff roles but not students', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('super_admin')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('staff')->hasPermissionTo('access_admin_panel'))->toBeTrue()
        ->and(Role::findByName('student')->hasPermissionTo('access_admin_panel'))->toBeFalse();
});

it('generates permissions in snake_case, not Shield default pascal', function () {
    $this->seed(RolePermissionSeeder::class);

    $names = Permission::pluck('name');

    expect($names)->toContain('view_any_role')
        ->and($names->filter(fn (string $n): bool => str_contains($n, ':')))->toBeEmpty();
});

/**
 * Guards against seeder/policy drift.
 *
 * Shield generates policies referencing abilities the seeder may not create.
 * When that happens the check fails closed — the action silently returns false
 * for everyone, including super_admin — which is easy to miss because nothing
 * errors. This asserts every permission any policy references actually exists.
 */
it('seeds every permission referenced by any policy', function () {
    $this->seed(RolePermissionSeeder::class);

    $seeded = Permission::pluck('name')->all();
    $policyDir = app_path('Policies');

    if (! is_dir($policyDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $missing = [];

    foreach (glob($policyDir.'/*.php') ?: [] as $policy) {
        preg_match_all(
            "/can\(\s*'([a-z0-9_]+)'/",
            (string) file_get_contents($policy),
            $matches,
        );

        foreach ($matches[1] as $ability) {
            if (! in_array($ability, $seeded, strict: true)) {
                $missing[] = basename($policy).' → '.$ability;
            }
        }
    }

    expect($missing)->toBeEmpty(
        'Policies reference permissions the seeder does not create: '
        .implode(', ', $missing),
    );
});
