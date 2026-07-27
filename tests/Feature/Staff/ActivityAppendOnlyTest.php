<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->entry = fn (): Activity => tap(
        activity()->event('created')->log('created'),
        fn () => null,
    );
});

/*
|--------------------------------------------------------------------------
| Nobody may change an entry — including the actors who hold everything
|--------------------------------------------------------------------------
*/

it('refuses every mutation ability for a super admin', function () {
    /*
     * The strongest actor in the system against every ability Filament might
     * consult. Asserted as a complete set rather than one or two, because an
     * ability added later and left undefined behaves like `false` today and like
     * whatever somebody writes tomorrow.
     */
    $superAdmin = ($this->actorWith)('super_admin');
    $activity = ($this->entry)();

    $recordAbilities = ['create', 'update', 'delete', 'forceDelete', 'restore', 'replicate'];
    $classAbilities = ['deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'];

    foreach ($recordAbilities as $ability) {
        expect(Gate::forUser($superAdmin)->allows($ability, $activity))->toBeFalse(
            "super_admin was permitted to {$ability} an activity entry.",
        );
    }

    foreach ($classAbilities as $ability) {
        expect(Gate::forUser($superAdmin)->allows($ability, Activity::class))->toBeFalse(
            "super_admin was permitted to {$ability} activity entries.",
        );
    }
});

it('refuses deletion to an actor who explicitly HOLDS delete_activity', function () {
    /*
     * THE ASSERTION THAT PROVES THE POLICY IGNORES THE GRANT.
     *
     * Production does not seed create/update/delete_activity at all — `activity`
     * is absent from the seeder's CRUD resource list. But a permission that does
     * not exist proves nothing about a policy: the refusal could just as easily
     * be "nobody holds it".
     *
     * So the permission is created HERE, granted to the strongest actor, and the
     * refusal asserted anyway. Making ActivityPolicy::delete() honour the grant
     * fails this test and only this test.
     */
    $superAdmin = ($this->actorWith)('super_admin');

    Permission::findOrCreate('delete_activity', 'web');
    $superAdmin->givePermissionTo('delete_activity');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($superAdmin->refresh()->can('delete_activity'))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', ($this->entry)()))->toBeFalse();
});

it('does not seed any activity write permission in production', function () {
    // The log is append-only, so abilities nothing may honour are not created —
    // seeding one invites somebody to wire it up later.
    foreach (['create_activity', 'update_activity', 'delete_activity'] as $ability) {
        expect(Permission::query()->where('name', $ability)->exists())->toBeFalse(
            "{$ability} is seeded; the activity log takes read permissions only.",
        );
    }

    foreach (['view_any_activity', 'view_activity'] as $ability) {
        expect(Permission::query()->where('name', $ability)->exists())->toBeTrue();
    }
});

/*
|--------------------------------------------------------------------------
| Reading it is permissioned normally
|--------------------------------------------------------------------------
*/

it('lets an admin read the log and refuses staff', function () {
    expect(Gate::forUser(($this->actorWith)('admin'))->allows('viewAny', Activity::class))->toBeTrue()
        ->and(Gate::forUser(($this->actorWith)('staff'))->allows('viewAny', Activity::class))->toBeFalse()
        ->and(Gate::forUser(($this->actorWith)('student'))->allows('viewAny', Activity::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The panel offers no way to write
|--------------------------------------------------------------------------
*/

it('registers no actions of any kind on the activity table', function () {
    /*
     * An allowlist of nothing. Every collection Filament consults is asserted
     * empty, so a control added later — of any class, under any name — fails
     * here rather than shipping a delete button on the audit trail.
     */
    ($this->entry)();

    $table = Livewire::actingAs(($this->actorWith)('super_admin'))
        ->test(ListActivities::class)
        ->instance()
        ->getTable();

    expect($table->getFlatActions())->toBeEmpty()
        ->and($table->getHeaderActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty()
        ->and($table->getFlatBulkActions())->toBeEmpty();
});

it('offers only a listing page, and refuses creation', function () {
    // No create, edit or view page: every extra surface is one more that would
    // have to be proven incapable of writing.
    expect(array_keys(ActivityResource::getPages()))->toBe(['index'])
        ->and(ActivityResource::canCreate())->toBeFalse();
});

it('renders the log for an authorized reader', function () {
    // The positive control. Without it, the emptiness assertions above would
    // hold just as well for a page that fails to load at all.
    ($this->entry)();

    Livewire::actingAs(($this->actorWith)('admin'))
        ->test(ListActivities::class)
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Readable output
|--------------------------------------------------------------------------
*/

it('renders event and record labels through translations, not raw values', function () {
    // Sentinels rather than the English copy: asserting "Roles changed" would
    // pass against a hardcoded string too, and would prove nothing about phase 4.
    Lang::addLines([
        'activity.event.roles_changed' => 'EVENT_SENTINEL',
        'activity.record_type.User' => 'RECORD_SENTINEL',
    ], app()->getLocale());

    expect(ActivityResource::eventLabel('roles_changed'))->toBe('EVENT_SENTINEL')
        ->and(ActivityResource::recordTypeLabel(User::class))->toBe('RECORD_SENTINEL');
});

it('falls back to the raw value when an event has no translation', function () {
    // Showing `some_new_event` beats showing `activity.event.some_new_event`.
    expect(ActivityResource::eventLabel('some_new_event'))->toBe('some_new_event')
        ->and(ActivityResource::eventLabel(null))->toBe(__('activity.empty_value'));
});

it('renders field changes as readable lines rather than raw JSON', function () {
    $admin = ($this->actorWith)('admin');
    $this->actingAs($admin);

    $admin->update(['name' => 'Renamed Person']);

    $entry = Activity::query()->where('event', 'updated')->latest('id')->first();

    $rendered = ActivityResource::describeChanges($entry);

    expect($rendered)->toContain('name')
        ->and($rendered)->toContain('Renamed Person')
        ->and($rendered)->not->toContain('{"')
        ->and($rendered)->not->toContain('attributes');
});
