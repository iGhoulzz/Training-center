<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Exceptions\RoleEscalationException;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Finding 3 — Filament's Select::relationship('roles') writes the pivot through
 * the relation's sync()/detach(), never through User::syncRoles(), so it slips
 * every escalation guard. The remediation supplies a safe pattern for Task 5:
 * SyncUserRolesAction, which routes role changes through the guarded
 * User::syncRoles(). These tests pin that the action is guarded.
 *
 * The whole file fails against pre-fix `main` because SyncUserRolesAction does
 * not exist there — which is precisely the point: the safe pattern is the
 * deliverable, and it was absent.
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

it('the safe action routes through the guards and blocks a staff role write', function () {
    $target = User::factory()->create();

    $this->actingAs($this->staff); // staff lacks assign_role

    expect(fn () => app(SyncUserRolesAction::class)->execute($target, ['admin']))
        ->toThrow(RoleEscalationException::class);

    expect($target->fresh()->hasRole('admin'))->toBeFalse();
});

it('the safe action blocks an admin from granting super_admin', function () {
    $this->actingAs($this->admin); // admin lacks assign_role and is not a super admin

    expect(fn () => app(SyncUserRolesAction::class)->execute($this->staff, ['staff', 'super_admin']))
        ->toThrow(RoleEscalationException::class);

    expect($this->staff->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('the safe action lets a super admin set roles', function () {
    $target = User::factory()->create();

    $this->actingAs($this->superAdmin);

    app(SyncUserRolesAction::class)->execute($target, ['admin']);

    expect($target->fresh()->hasRole('admin'))->toBeTrue();
});

/*
 * Documents WHY the action exists: the raw relationship writer Filament would
 * otherwise use bypasses the guards entirely. This holds on both main and the
 * fixed tree — the remediation provides a safe alternative rather than making
 * the raw relation safe — so it is a hazard note, not a before/after assertion.
 */
it('documents that the raw roles relation bypasses the guards', function () {
    $target = User::factory()->create();
    $superAdminRoleId = app('db')->table('roles')->where('name', 'super_admin')->value('id');

    $this->actingAs($this->staff);

    // This is what Select::relationship('roles') does under the hood.
    $target->roles()->sync([$superAdminRoleId]);

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});
