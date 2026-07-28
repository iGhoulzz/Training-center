<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
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
    // Listing and view only — no create page and no edit page. The view page is
    // asserted separately to register no actions of its own.
    expect(array_keys(ActivityResource::getPages()))->toBe(['index', 'view'])
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

/*
|--------------------------------------------------------------------------
| Attribution survives the actor
|--------------------------------------------------------------------------
*/

it('still names the actor after their account is deleted', function () {
    /*
     * THE FINDING THIS EXISTS FOR.
     *
     * causer is a relation to a soft-deleting model, so resolving it at read time
     * returns null once the account goes — and the panel would render a real
     * person's action as "System". That is not cosmetic: it says a machine did
     * something a human did, in the one table that exists to answer who did what.
     */
    $actor = ($this->actorWith)('admin');
    $actor->update(['name' => 'Hana Ben Salah']);

    $this->actingAs($actor);
    Student::factory()->create();

    $entry = Activity::query()->where('event', 'created')->latest('id')->first();

    $actor->delete();

    $label = ActivityResource::actorLabel($entry->fresh());

    expect($label)->toContain('Hana Ben Salah')
        ->and($label)->not->toBe(__('activity.system'));
});

it('keeps the name recorded at the time, not the name today', function () {
    // A rename must not rewrite history. The snapshot is what the actor was
    // called when they acted.
    $actor = ($this->actorWith)('admin');
    $actor->update(['name' => 'Original Name']);

    $this->actingAs($actor);
    Student::factory()->create();

    $entry = Activity::query()->where('event', 'created')->latest('id')->first();

    $actor->update(['name' => 'Renamed Later']);

    expect(ActivityResource::actorLabel($entry->fresh()))->toBe('Original Name');
});

it('says System only when there genuinely was no actor', function () {
    // The distinction the snapshot exists to preserve: a seeder is the system, a
    // deleted account is a person.
    $user = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($user, 'staff');

    $entry = Activity::query()->where('event', 'roles_changed')->latest('id')->first();

    expect($entry->causer_id)->toBeNull()
        ->and(ActivityResource::actorLabel($entry))->toBe(__('activity.system'));
});

/*
|--------------------------------------------------------------------------
| Explicit-event detail and subject identity are visible
|--------------------------------------------------------------------------
*/

it('renders which roles changed, not merely that they did', function () {
    /*
     * The substance of every explicit event lives in properties, not in
     * attribute_changes. Without rendering it the panel says "Roles changed" and
     * never says which roles — most of the answer missing.
     */
    $target = User::factory()->create(['is_active' => true]);

    app(SyncUserRolesAction::class)
        ->execute(($this->actorWith)('super_admin'), $target, ['staff']);

    $entry = Activity::query()->where('event', 'roles_changed')->latest('id')->first();

    $rendered = ActivityResource::describeProperties($entry);

    expect($rendered)->toContain('staff')
        ->and($rendered)->toContain('added')
        // Context has its own columns and is not repeated as detail.
        ->and($rendered)->not->toContain('ip')
        ->and($rendered)->not->toContain('causer_name');
});

it('identifies which record an entry is about, not only its type', function () {
    $this->actingAs(($this->actorWith)('admin'));

    $student = Student::factory()->create();

    $entry = Activity::query()->where('event', 'created')->latest('id')->first();

    expect(ActivityResource::subjectLabel($entry))->toContain((string) $student->getKey());
});

it('offers a read-only view page that registers no actions', function () {
    ($this->entry)();

    $activity = Activity::query()->latest('id')->first();

    Livewire::actingAs(($this->actorWith)('admin'))
        ->test(ViewActivity::class, [
            'record' => $activity->getKey(),
        ])
        ->assertSuccessful()
        /*
         * ViewRecord inherits an edit action when the resource has an edit page.
         * This one has none, and these assert that the inheritance stays absent
         * rather than trusting that it does.
         */
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('restore')
        ->assertActionDoesNotExist('forceDelete');
});

/*
|--------------------------------------------------------------------------
| The filters actually filter
|--------------------------------------------------------------------------
|
| Mounting the page proves it renders. It says nothing about whether a filter
| narrows anything, which is the only reason the filters exist.
*/

it('filters the log by actor', function () {
    $mine = ($this->actorWith)('admin');
    $theirs = ($this->actorWith)('admin');

    $this->actingAs($mine);
    Student::factory()->create();
    $ours = Activity::query()->latest('id')->first();

    $this->actingAs($theirs);
    Student::factory()->create();
    $others = Activity::query()->latest('id')->first();

    Livewire::actingAs($mine)
        ->test(ListActivities::class)
        ->filterTable('causer_id', $mine->getKey())
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$others]);
});

it('filters the log by record type', function () {
    $admin = ($this->actorWith)('admin');
    $this->actingAs($admin);

    Student::factory()->create();
    $studentEntry = Activity::query()->latest('id')->first();

    Course::factory()->create();
    $courseEntry = Activity::query()->latest('id')->first();

    Livewire::actingAs($admin)
        ->test(ListActivities::class)
        ->filterTable('subject_type', Student::class)
        ->assertCanSeeTableRecords([$studentEntry])
        ->assertCanNotSeeTableRecords([$courseEntry]);
});

it('filters the log by date range, inclusively at both ends', function () {
    /*
     * The boundaries are the point. The filter compares timestamps rather than
     * calling whereDate() — so it can use the created_at index — and startOfDay /
     * endOfDay are what keep "from today until today" matching everything that
     * happened today rather than only midnight exactly.
     */
    $admin = ($this->actorWith)('admin');
    $this->actingAs($admin);

    Student::factory()->create();
    $today = Activity::query()->latest('id')->first();

    Student::factory()->create();
    $old = Activity::query()->latest('id')->first();
    $old->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    Livewire::actingAs($admin)
        ->test(ListActivities::class)
        ->filterTable('created_at', [
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$old->fresh()]);
});

it('filters the log by log name', function () {
    $admin = ($this->actorWith)('admin');

    activity('auth')->event('login_failed')->log('login_failed');
    $auth = Activity::query()->latest('id')->first();

    $this->actingAs($admin);
    Student::factory()->create();
    $default = Activity::query()->latest('id')->first();

    Livewire::actingAs($admin)
        ->test(ListActivities::class)
        ->filterTable('log_name', 'auth')
        ->assertCanSeeTableRecords([$auth])
        ->assertCanNotSeeTableRecords([$default]);
});

it('offers departed staff in the actor filter', function () {
    // A deleted account is still an actor in the history; dropping it from the
    // filter would make its entries unreachable.
    $departed = ($this->actorWith)('admin');
    $departed->update(['name' => 'Departed Person']);
    $departed->delete();

    $options = Livewire::actingAs(($this->actorWith)('admin'))
        ->test(ListActivities::class)
        ->instance()
        ->getTable()
        ->getFilter('causer_id')
        ->getOptions();

    expect($options)->toHaveKey($departed->getKey());
});

it('compares the date filter against a bare column so the index is usable', function () {
    /*
     * A SHAPE ASSERTION, BECAUSE BEHAVIOUR CANNOT SEE THIS.
     *
     * whereDate() and a timestamp range return identical rows, so the inclusivity
     * test above passes either way — reverting to whereDate() fails nothing.
     * The difference is that DATE(created_at) is non-sargable: MySQL cannot use
     * the created_at index and scans the table, on the one table here that only
     * ever grows.
     *
     * So the emitted SQL is asserted directly: the filter may compare created_at,
     * but must not wrap it in a function.
     */
    $admin = ($this->actorWith)('admin');
    $this->actingAs($admin);

    Student::factory()->create();

    $statements = captureStatements();

    Livewire::actingAs($admin)
        ->test(ListActivities::class)
        ->filterTable('created_at', [
            'from' => now()->toDateString(),
            'until' => now()->toDateString(),
        ]);

    $wrapped = collect($statements)
        ->filter(fn (array $s): bool => str_contains($s['sql'], 'activity_log')
            && preg_match('/date\([^)]*created_at/', $s['sql']) === 1)
        ->count();

    expect($wrapped)->toBe(
        0,
        'The date filter wrapped created_at in DATE(), which makes the comparison non-sargable '
        .'and the created_at index unusable. Statements: '.describeStatements($statements),
    );
});
