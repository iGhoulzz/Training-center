<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The safe pattern Task 5's UserResource must use for the roles field.
 *
 * Filament's Select::relationship('roles') persists by calling the relation's
 * sync()/detach() directly — it never runs the escalation guards. The safe
 * pattern is to detach the field from the relationship writer and route the
 * selected roles through SyncUserRolesAction with the authenticated actor. This
 * file pins that the action IS the guarded path and documents WHY the raw
 * relation is not (the architecture test now forbids that raw call in app code).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->sync = app(SyncUserRolesAction::class);
});

it('routes through the guards and blocks an unauthorized role write', function () {
    $target = User::factory()->create();

    // Admins hold assign_role since P1-T05b, so the boundary they hit is
    // guard 1: they may set lesser roles but never super_admin.
    expect(fn () => $this->sync->execute($this->admin, $target, ['super_admin']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('lets a super admin set roles through the action', function () {
    $target = User::factory()->create();

    $this->sync->execute($this->superAdmin, $target, ['admin']);

    expect($target->fresh()->hasRole('admin'))->toBeTrue();
});

it('documents that the raw roles relation bypasses the guards (hence the arch test)', function () {
    // This is what Select::relationship('roles') does under the hood. It writes
    // the pivot with no guard at all — which is exactly why the boundary is
    // enforced by an Action and application code is forbidden from doing this
    // (see ActionBoundaryArchTest). Reproduced here only to document the hazard.
    $target = User::factory()->create();
    $superAdminRoleId = app('db')->table('roles')->where('name', 'super_admin')->value('id');

    $target->roles()->sync([$superAdminRoleId]);

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});
