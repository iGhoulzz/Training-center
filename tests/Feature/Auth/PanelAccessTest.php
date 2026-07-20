<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('allows an active staff user into the admin panel', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('staff');

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('denies a deactivated user', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('denies a student the admin panel', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('denies a user with no role', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});
