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
