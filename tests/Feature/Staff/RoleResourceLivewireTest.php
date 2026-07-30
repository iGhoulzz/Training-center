<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

it('registers no bulk delete action on the roles table at all', function () {
    /*
     * STRENGTHENED BY P1-T15, security review finding 3.
     *
     * This used to assert the action was HIDDEN, reasoning that "asserting
     * hidden rather than does not exist documents the real mechanism: the
     * policy is what removes it". Accurate — and the weaker of the two
     * available guarantees. A control that renders only in order to be refused
     * sits one policy edit away from working, so RoleResource now drops
     * Shield's toolbar outright.
     *
     * The policy still refuses, asserted separately below. Defence in depth
     * means both, not either.
     *
     * NOT assertTableBulkActionDoesNotExist('delete'): that helper resolves an
     * action by NAME across the whole table, so it finds the record-level
     * DeleteAction — which is deliberately still there — and reports a bulk
     * action that does not exist. The bulk collections are the precise question,
     * and they are the same idiom BatchResourceTest and CourseResourceTest use.
     */
    $table = Livewire::actingAs($this->superAdmin)
        ->test(ListRoles::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

it('still refuses bulk deletion at the policy, with no action left to render', function () {
    // The second layer, and the one that matters if a toolbar action is ever
    // re-added. Filament consults deleteAny() only because RolePolicy defines
    // it — an omitted method would be an allow, not a deny.
    $this->actingAs($this->superAdmin);

    expect(RoleResource::canDeleteAny())->toBeFalse();
});

it('leaves every role intact when a bulk delete is invoked directly', function () {
    $countBefore = Role::count();

    // A crafted Livewire payload naming an action the table no longer registers.
    // Filament raises rather than performing it; the exception type is Filament's
    // business, so this asserts the OUTCOME — every row still there — which is
    // what the test is actually for.
    try {
        Livewire::actingAs($this->superAdmin)
            ->test(ListRoles::class)
            ->callTableBulkAction('delete', Role::pluck('id')->all());
    } catch (Throwable) {
        // Expected: there is no such action to call.
    }

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
