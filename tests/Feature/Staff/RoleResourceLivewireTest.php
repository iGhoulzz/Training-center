<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Drives the real role table component instead of pattern-matching source.
 *
 * The architecture tests scan for known-bad code shapes, which is inherently a
 * game of catch-up: `->relationship(name: 'roles')`, a declarative
 * `DeleteAction::make()`, and instance-level writes all read differently to a
 * regex while behaving identically at runtime. These tests assert the OUTCOME
 * through the actual Filament/Livewire stack, so they hold regardless of how
 * the bypass is spelled.
 *
 * Architecture tests still earn their place — they fail fast and point at the
 * offending file — but they are the cheap early warning, not the proof.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->superAdmin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->superAdmin, 'super_admin');
});

it('hides the bulk delete action on the roles table, even from a super admin', function () {
    // Shield's resource still registers a DeleteBulkAction, so the object
    // exists — but deleteAny() denies, so Filament must never render it.
    // Asserting "hidden" rather than "does not exist" documents the real
    // mechanism: the policy is what removes it, not the absence of the action.
    Livewire::actingAs($this->superAdmin)
        ->test(ListRoles::class)
        ->assertOk()
        ->assertTableBulkActionHidden('delete');
});

it('leaves every role intact when a bulk delete is invoked directly', function () {
    $superAdminRole = Role::where('name', Role::SUPER_ADMIN)->firstOrFail();
    $staffRole = Role::where('name', 'staff')->firstOrFail();
    $countBefore = Role::count();

    // A hidden action is not merely absent from the markup — invoking it the
    // way a crafted Livewire payload would must also fail. Filament's helper
    // asserts visibility first, so the refusal surfaces as an expectation
    // failure. Catch ONLY that: a broader catch would swallow a genuine error
    // and let this test pass for the wrong reason.
    // Stated as an explicit expectation rather than try/catch: the previous
    // form needed the reader to know that ExpectationFailedException is a
    // SUBclass of the AssertionFailedError that fail() throws, so the guard
    // could not swallow itself. Correct, but not worth the reasoning.
    expect(fn () => Livewire::actingAs($this->superAdmin)
        ->test(ListRoles::class)
        ->callTableBulkAction('delete', [
            $superAdminRole->getKey(),
            $staffRole->getKey(),
        ]))->toThrow(ExpectationFailedException::class);

    expect(Role::where('name', Role::SUPER_ADMIN)->exists())->toBeTrue()
        ->and(Role::where('name', 'staff')->exists())->toBeTrue()
        ->and(Role::count())->toBe($countBefore);
});

it('denies the roles list to a user without role permissions', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($admin, 'admin');

    Livewire::actingAs($admin)
        ->test(ListRoles::class)
        ->assertForbidden();
});
