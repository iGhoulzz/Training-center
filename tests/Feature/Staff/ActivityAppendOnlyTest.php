<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Exceptions\ActivityLogIsAppendOnlyException;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\Config;
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
| The console surface (P1-T15, group 3 finding H1)
|--------------------------------------------------------------------------
|
| THE HOLE THIS SUITE COULD NOT SEE. Every other test here proves the policy,
| the resource and the pages are closed, and none of them ever looked at the
| command line. `activitylog:clean` is a registered artisan command that issues
| `DELETE FROM activity_log WHERE created_at < ?`, and this project configured it
| with a live 365-day window — so ActivityPolicy's claim that "no policy, no UI
| control and no application code path can remove or alter an entry" was false,
| and docs/ENGINEERING.md explicitly places commands that use application code
| inside the trust boundary rather than in the raw-SQL escape hatch.
|
| The refusal is placed in the ACTION rather than only in the command, because
| the action is what any future caller reaches — a queued job, a scheduled task,
| another package. Closing only the CLI would leave the code path open and merely
| hide its most obvious door.
*/

it('refuses to clean the activity log from the command line', function () {
    $old = ($this->entry)();
    $old->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

    $this->artisan('activitylog:clean')->assertFailed();

    expect(Activity::whereKey($old->getKey())->exists())->toBeTrue(
        'activitylog:clean deleted an audit entry, so the append-only rule has an '
        .'application code path straight through it.',
    );
});

it('refuses even when the caller names its own retention window', function () {
    /*
     * --days overrides config, so a fix that only neutralised clean_after_days
     * would be bypassed by the most obvious next thing an operator types. This
     * path gets past that barrier and is stopped by the action instead.
     *
     * ASSERTED AS A THROW RATHER THAN AN EXIT CODE, and the difference is the
     * test harness rather than the behaviour: PendingCommand::run() calls the
     * console kernel directly, so the exception propagates here, while in
     * production the kernel's own handler renders it and exits non-zero. Either
     * way the operator gets the refusal and the entry survives, which is what
     * the assertions below check.
     */
    $old = ($this->entry)();
    $old->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

    try {
        $this->artisan('activitylog:clean', ['--days' => 1])->run();
        $thrown = null;
    } catch (ActivityLogIsAppendOnlyException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ActivityLogIsAppendOnlyException::class)
        ->and(Activity::whereKey($old->getKey())->exists())->toBeTrue(
            'activitylog:clean --days deleted an audit entry, so neutralising the configured '
            .'retention window was the only barrier and it is trivially bypassed.',
        );
});

it('refuses the cleaning action itself, not merely the command that calls it', function () {
    /*
     * THE BOUNDARY, NOT THE DOOR. A queued job or another package resolving the
     * configured action reaches this without an artisan command in front of it,
     * exactly as SyncUserRolesAction can be reached without a Filament Select.
     */
    $old = ($this->entry)();
    $old->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

    expect(fn () => Config::cleanActivityLogAction()->execute(1))
        ->toThrow(ActivityLogIsAppendOnlyException::class);

    expect(Activity::whereKey($old->getKey())->exists())->toBeTrue();
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

it('names nobody in the properties of a system entry either', function () {
    /*
     * P1-T15, group 3 finding M2, confirmed by experiment before the fix: a
     * system write made while a super admin was signed in produced causer_id
     * null, causer_type null, and properties.causer_name "Signed In Person".
     *
     * causedByAnonymous() nulls the two COLUMNS and leaves the relation that
     * causedBy() associated, so the context recorder still found a Model and
     * snapshotted its name. The panel then renders "System" from the null
     * causer_id while the stored row names somebody for a change they did not
     * make — and the Who column searches properties->causer_name, so that
     * person's name MATCHES system rows.
     *
     * Signed in deliberately: with nobody authenticated there is no name to
     * leak and the test would pass against the bug.
     */
    $signedIn = ($this->actorWith)('super_admin');
    $this->actingAs($signedIn);

    $target = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($target, 'staff');

    $entry = Activity::query()->where('event', 'roles_changed')->latest('id')->first();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->getProperty('causer_name'))->toBeNull(
            'A system entry carries a real person\'s name in its properties, so the log '
            .'attributes to them a change they did not make.',
        );
});

it('still records the name on an entry that genuinely has an actor', function () {
    // The control. Dropping causer_name altogether would satisfy the test above
    // and destroy the snapshot that lets the log name someone after their
    // account is deleted.
    $actor = ($this->actorWith)('super_admin');
    $target = User::factory()->create(['is_active' => true]);

    app(SyncUserRolesAction::class)->execute($actor, $target, ['staff']);

    $entry = Activity::query()->where('event', 'roles_changed')->latest('id')->first();

    expect($entry->causer_id)->toBe($actor->getKey())
        ->and($entry->getProperty('causer_name'))->toBe($actor->name);
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

/*
|--------------------------------------------------------------------------
| The audit trail cannot be switched off by an empty string (finding M1)
|--------------------------------------------------------------------------
*/

it('stays enabled when ACTIVITYLOG_ENABLED is present but blank', function () {
    /*
     * env()'s second argument is a default for a MISSING key. A key that exists
     * and is empty returns '', sails past the default, and Spatie's
     * ActivityLogStatus has no declare(strict_types=1) — so coercive typing
     * turns '' into false and the ENTIRE AUDIT TRAIL silently stops recording.
     *
     * Nothing fails, nothing warns, and the panel keeps rendering the entries
     * written before the deploy. The first time anyone notices is when they go
     * looking for who did something and the log stops at a date.
     *
     * Re-evaluates the config file with the variable blank, because the booted
     * config was resolved before this test ran. Same technique
     * BackupConfigurationTest uses for a blank BACKUP_ALERT_EMAIL, which is the
     * same class of bug in a different package.
     */
    $original = $_ENV['ACTIVITYLOG_ENABLED'] ?? null;

    $_ENV['ACTIVITYLOG_ENABLED'] = '';
    putenv('ACTIVITYLOG_ENABLED=');

    try {
        $resolved = (require config_path('activitylog.php'))['enabled'];
    } finally {
        if ($original === null) {
            unset($_ENV['ACTIVITYLOG_ENABLED']);
            putenv('ACTIVITYLOG_ENABLED');
        } else {
            $_ENV['ACTIVITYLOG_ENABLED'] = $original;
            putenv('ACTIVITYLOG_ENABLED='.$original);
        }
    }

    expect($resolved)->toBeTrue(
        'A blank ACTIVITYLOG_ENABLED disables the audit trail, and nothing anywhere '
        .'reports that it happened.',
    );
});

it('can still be switched off deliberately', function () {
    // The control: the coercion must read a real "off" as off, or the fix has
    // simply hardcoded true and removed the setting.
    $original = $_ENV['ACTIVITYLOG_ENABLED'] ?? null;

    $_ENV['ACTIVITYLOG_ENABLED'] = 'false';
    putenv('ACTIVITYLOG_ENABLED=false');

    try {
        $resolved = (require config_path('activitylog.php'))['enabled'];
    } finally {
        if ($original === null) {
            unset($_ENV['ACTIVITYLOG_ENABLED']);
            putenv('ACTIVITYLOG_ENABLED');
        } else {
            $_ENV['ACTIVITYLOG_ENABLED'] = $original;
            putenv('ACTIVITYLOG_ENABLED='.$original);
        }
    }

    expect($resolved)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The role form must not advertise write abilities (finding L5)
|--------------------------------------------------------------------------
*/

it('offers no activity write permission on the role form', function () {
    /*
     * P1-T15, group 3 finding L5, confirmed by experiment before the fix:
     * FilamentShield::getAllResourcePermissionsWithLabels() returned 84 options
     * of which TWELVE were *_activity, including delete_activity,
     * force_delete_any_activity, restore_activity and reorder_activity.
     *
     * The seeder deliberately creates only view_any_activity and view_activity,
     * so the form advertised capabilities that cannot be granted — on a log
     * whose non-negotiable rule is that no such path exists. Nothing was
     * exploitable: ActivityPolicy refuses every one of them regardless. The harm
     * is that it TEACHES THE WRONG THING. An administrator reading a checkbox
     * labelled "delete activity" reasonably concludes the log is deletable by
     * someone, and the next person to act on that belief writes a feature to
     * match it.
     */
    $writeAbilities = ['create', 'update', 'delete', 'delete_any', 'force_delete',
        'force_delete_any', 'restore', 'restore_any', 'replicate', 'reorder'];

    $offered = collect(FilamentShield::getAllResourcePermissionsWithLabels())
        ->keys()
        ->filter(fn (string $permission): bool => str_ends_with($permission, '_activity'))
        ->values();

    // The read permissions must survive: excluding the resource outright would
    // also remove the two the seeder really does create, and the panel needs.
    expect($offered)->toContain('view_any_activity')
        ->and($offered)->toContain('view_activity');

    $writes = $offered->filter(
        fn (string $permission): bool => collect($writeAbilities)
            ->contains(fn (string $ability): bool => $permission === $ability.'_activity'),
    )->values()->all();

    expect($writes)->toBeEmpty(
        'The role form offers activity write permissions the seeder never creates, on a log '
        .'that has no write path at all: '.implode(', ', $writes),
    );
});
