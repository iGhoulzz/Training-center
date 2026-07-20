<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

// Guard 1: an admin cannot touch a super admin
it('forbids an admin from updating a super admin', function () {
    expect($this->admin->can('update', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from deleting a super admin', function () {
    expect($this->admin->can('delete', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from assigning the super_admin role', function () {
    expect($this->admin->can('assignRole', [User::class, 'super_admin']))->toBeFalse();
});

it('allows an admin to update a staff user', function () {
    expect($this->admin->can('update', $this->staff))->toBeTrue();
});

it('allows a super admin to update another super admin', function () {
    $other = User::factory()->create();
    $other->assignRole('super_admin');

    expect($this->superAdmin->can('update', $other))->toBeTrue();
});

// Guard 2: nobody edits their own roles
it('allows a super admin to assign the admin role generally', function () {
    expect($this->superAdmin->can('assignRole', [User::class, 'admin']))->toBeTrue();
});

it('forbids a super admin from changing their own roles', function () {
    expect($this->superAdmin->can('modifyOwnRoles', $this->superAdmin))->toBeFalse();
});

it('forbids an admin from deleting their own account', function () {
    expect($this->admin->can('delete', $this->admin))->toBeFalse();
});

// Guard 3: the last super admin is protected
it('refuses to delete the last active super admin', function () {
    $this->superAdmin->delete();
})->throws(LastSuperAdminException::class);

it('refuses to deactivate the last active super admin', function () {
    $this->superAdmin->update(['is_active' => false]);
})->throws(LastSuperAdminException::class);

it('allows deleting a super admin when another active one exists', function () {
    $second = User::factory()->create(['is_active' => true]);
    $second->assignRole('super_admin');

    $this->superAdmin->delete();

    expect(User::find($this->superAdmin->id))->toBeNull();
});

it('does not count an inactive super admin as a survivor', function () {
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('super_admin');

    $this->superAdmin->delete();
})->throws(LastSuperAdminException::class);
