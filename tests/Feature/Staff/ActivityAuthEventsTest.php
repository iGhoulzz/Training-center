<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\DeleteStaffPhotoAction;
use App\Domain\Staff\Actions\DeleteStaffProfileAction;
use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Filament\Pages\PasswordChange;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\ActivityBuffer;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->superAdmin = ($this->actorWith)('super_admin');

    $this->entriesForEvent = fn (string $event) => Activity::query()
        ->where('event', $event)
        ->latest('id')
        ->get();
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('logs a successful sign-in against the account', function () {
    $user = ($this->actorWith)('admin');

    Event::dispatch(new Login('web', $user, false));

    $entry = ($this->entriesForEvent)('logged_in')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('auth')
        ->and((int) $entry->causer_id)->toBe((int) $user->getKey())
        ->and($entry->getProperty('ip'))->toBe(request()->ip());
});

it('logs a sign-out', function () {
    $user = ($this->actorWith)('admin');

    Event::dispatch(new Logout('web', $user));

    expect(((int) ($this->entriesForEvent)('logged_out')->first()->causer_id))
        ->toBe((int) $user->getKey());
});

it('logs a failed attempt with the attempted email and no causer', function () {
    /*
     * A failed attempt has NO causer — the whole point is that nobody proved who
     * they were. The identifier is recorded so a run of attempts against one
     * account is visible; the submitted password never is, not even hashed.
     */
    Event::dispatch(new Failed('web', null, [
        'email' => 'someone@example.test',
        'password' => 'the-attempted-secret',
    ]));

    $entry = ($this->entriesForEvent)('login_failed')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->getProperty('email'))->toBe('someone@example.test')
        ->and($entry->getProperty('ip'))->toBe(request()->ip())
        ->and(json_encode($entry->properties->all()))->not->toContain('the-attempted-secret');
});

/*
|--------------------------------------------------------------------------
| Password events, which the diff cannot carry
|--------------------------------------------------------------------------
*/

it('records an administrator resetting somebody else s password', function () {
    /*
     * `password` is excluded from the diff, so without an explicit event this
     * would be invisible — and on an account already flagged for rotation the
     * change set would be empty and suppressed entirely.
     */
    $target = User::factory()->create(['must_change_password' => true]);

    $plain = app(ResetUserPasswordAction::class)->execute($this->superAdmin, $target);

    $entry = ($this->entriesForEvent)('password_reset')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->subject_id)->toBe((int) $target->getKey())
        ->and((int) $entry->causer_id)->toBe((int) $this->superAdmin->getKey());

    // The new password never reaches the log, in any column.
    $everything = json_encode([$entry->properties->all(), $entry->attribute_changes?->all()]);

    expect($everything)->not->toContain($plain)
        ->and($everything)->not->toContain('$2y$');
});

it('records somebody changing their own password', function () {
    // Kept distinct from a reset: they answer different questions, and only one
    // of them involves a second party.
    $user = ($this->actorWith)('admin');
    $this->actingAs($user);

    Livewire::test(PasswordChange::class)
        ->fillForm(['password' => 'a-fresh-secret-1', 'password_confirmation' => 'a-fresh-secret-1'])
        ->call('save');

    $entry = ($this->entriesForEvent)('password_changed')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->causer_id)->toBe((int) $user->getKey());

    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('a-fresh-secret-1');
});

/*
|--------------------------------------------------------------------------
| Pivot changes, which fire no model event
|--------------------------------------------------------------------------
*/

it('records a role assignment against the account', function () {
    $target = User::factory()->create(['is_active' => true]);

    app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);

    $entry = ($this->entriesForEvent)('roles_changed')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->subject_id)->toBe((int) $target->getKey())
        ->and((int) $entry->causer_id)->toBe((int) $this->superAdmin->getKey())
        ->and($entry->getProperty('added'))->toBe(['staff'])
        ->and($entry->getProperty('removed'))->toBe([]);
});

it('records a permission change against the role', function () {
    $role = Role::findOrCreate('registrar', 'web');

    app(UpdateRolePermissionsAction::class)
        ->execute($this->superAdmin, $role, ['view_any_student']);

    $entry = ($this->entriesForEvent)('permissions_changed')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->subject_id)->toBe((int) $role->getKey())
        ->and($entry->getProperty('added'))->toBe(['view_any_student']);
});

it('writes no event when a role sync changes nothing', function () {
    // Repeatable seeding must not manufacture audit events. A second identical
    // sync is a no-op and records nothing.
    $target = User::factory()->create(['is_active' => true]);

    app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);
    $after = ($this->entriesForEvent)('roles_changed')->count();

    app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);

    expect(($this->entriesForEvent)('roles_changed')->count())->toBe($after);
});

it('writes no event when a permission sync changes nothing', function () {
    $role = Role::findOrCreate('registrar', 'web');
    $permissions = [Permission::findByName('view_any_student', 'web')->name];

    app(UpdateRolePermissionsAction::class)->execute($this->superAdmin, $role, $permissions);
    $after = ($this->entriesForEvent)('permissions_changed')->count();

    app(UpdateRolePermissionsAction::class)->execute($this->superAdmin, $role, $permissions);

    expect(($this->entriesForEvent)('permissions_changed')->count())->toBe($after);
});

it('attributes a system write to nobody, even with a user signed in', function () {
    /*
     * SystemRoleWriter runs from seeders and console commands, where whatever
     * session happens to exist is not the author of the change. Attributing a
     * seeder's rewrite of the permission matrix to whoever was logged in names a
     * person for something they did not do.
     */
    $this->actingAs($this->superAdmin);

    $target = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($target, 'staff');

    $entry = Activity::query()
        ->where('event', 'roles_changed')
        ->where('subject_id', $target->getKey())
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->causer_type)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Deletes the database performs, which fire no model event
|--------------------------------------------------------------------------
*/

it('records certificates removed by the profile cascade', function () {
    /*
     * staff_certificates.staff_profile_id is cascadeOnDelete, so the DATABASE
     * removes those rows and no Eloquent event fires. Without the explicit
     * record they would simply cease to exist, with nothing saying they had.
     */
    $profile = StaffProfile::factory()->create();
    $certificates = StaffCertificate::factory()->count(2)->for($profile)->create();

    app(DeleteStaffProfileAction::class)->execute($this->superAdmin, $profile);

    $entries = ($this->entriesForEvent)('deleted_by_cascade');

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('subject_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe($certificates->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all());

    // Titles, not storage paths.
    $everything = json_encode($entries->pluck('properties')->toArray());

    expect($everything)->not->toContain('.pdf')
        ->and($everything)->not->toContain('staff-certificates/');
});

/*
|--------------------------------------------------------------------------
| Photo events, which the diff cannot carry either
|--------------------------------------------------------------------------
*/

it('records a photo removal without the path', function () {
    $profile = StaffProfile::factory()->create([
        'profile_photo_path' => 'staff-photos/personal-name-here.png',
    ]);

    app(DeleteStaffPhotoAction::class)
        ->execute($this->superAdmin, $profile);

    $entry = ($this->entriesForEvent)('photo_removed')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->subject_id)->toBe((int) $profile->getKey());

    expect(json_encode(Activity::query()->get()->toArray()))
        ->not->toContain('personal-name-here');
});

/*
|--------------------------------------------------------------------------
| The pivot diff is computed under the lock, inside the transaction
|--------------------------------------------------------------------------
*/

it('locks the account before reading the roles it audits', function () {
    /*
     * THE ORDERING THE AUDIT TRAIL'S ACCURACY DEPENDS ON.
     *
     * The diff used to be computed before the transaction opened. Two concurrent
     * syncs would then both read the same "current" set and each record an
     * added/removed list against a state that had already moved — the rows would
     * end up right and the log would describe a change that never happened that
     * way, which is the worse failure because nobody notices it.
     *
     * Asserted as an ORDER at a DEPTH, because each property alone is satisfiable
     * by the broken arrangement: a lock happens, a pivot read happens, a write
     * happens.
     */
    $target = User::factory()->create(['is_active' => true]);

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);

    $ordered = collect($statements)->values();

    $lock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `users`'));

    $pivotRead = $ordered->search(fn (array $s): bool => str_starts_with($s['sql'], 'select')
        && str_contains($s['sql'], 'model_has_roles'));

    $auditWrite = $ordered->search(fn (array $s): bool => str_starts_with($s['sql'], 'insert into `activity_log`'));

    expect($lock)->not->toBeFalse(
        'The account was never locked, so the roles read below raced any concurrent sync. '
        .describeStatements($statements),
    );

    expect($pivotRead)->not->toBeFalse('No pivot read observed. '.describeStatements($statements))
        ->and($auditWrite)->not->toBeFalse('No audit entry written. '.describeStatements($statements));

    expect($lock)->toBeLessThan(
        $pivotRead,
        'The roles were read BEFORE the account was locked, so the recorded diff can describe '
        .'a state that had already changed.',
    );

    expect($pivotRead)->toBeLessThan($auditWrite);

    foreach ([$lock, $pivotRead, $auditWrite] as $index) {
        expect($ordered[$index]['level'])->toBe(
            $baseline + 1,
            'Statement ran outside the Action\'s transaction: '.$ordered[$index]['sql'],
        );
    }
});

it('locks the role before reading the permissions it audits', function () {
    $role = Role::findOrCreate('registrar', 'web');

    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    app(UpdateRolePermissionsAction::class)
        ->execute($this->superAdmin, $role, ['view_any_student']);

    $ordered = collect($statements)->values();

    $lock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
        && str_contains($s['sql'], 'from `roles`'));

    $auditWrite = $ordered->search(fn (array $s): bool => str_starts_with($s['sql'], 'insert into `activity_log`'));

    expect($lock)->not->toBeFalse(
        'The role was never locked. '.describeStatements($statements),
    );

    expect($auditWrite)->not->toBeFalse()
        ->and($lock)->toBeLessThan($auditWrite)
        ->and($ordered[$lock]['level'])->toBe($baseline + 1)
        ->and($ordered[$auditWrite]['level'])->toBe($baseline + 1);
});

it('writes the audit entry in the same transaction as the pivot change', function () {
    // A rollback must take the entry with it. Recording outside the transaction
    // would leave a log claiming a role change that never landed.
    $target = User::factory()->create(['is_active' => true]);
    $before = Activity::query()->count();

    try {
        DB::transaction(function () use ($target): void {
            app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);

            throw new RuntimeException('the surrounding operation failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    app(ActivityBuffer::class)->flush();

    expect(Activity::query()->count())->toBe($before)
        ->and($target->fresh()->roles()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Authorization is decided under the lock, not before it
|--------------------------------------------------------------------------
*/

it('refuses a permission change when the actor gains the role mid-transaction', function () {
    /*
     * THE STALE-AUTHORIZATION WINDOW.
     *
     * RolePolicy::update() refuses a role the actor HOLDS — nobody edits the
     * permissions of a role they belong to. Asked before the lock, that answer
     * could go stale: an actor granted the role between the check and the write
     * kept an authorization that was no longer true, and the change landed.
     *
     * The grant is injected from a listener on the role lock, which is after the
     * transaction opens and before the policy runs. Moving the authorize() back
     * outside the transaction makes this pass again.
     */
    $actor = ($this->actorWith)('super_admin');
    $role = Role::findOrCreate('registrar', 'web');

    $granted = false;

    DB::listen(function (QueryExecuted $query) use (&$granted, $actor, $role): void {
        if ($granted || ! str_contains(strtolower($query->sql), 'from `roles`')) {
            return;
        }

        if (! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        $granted = true;

        DB::table('model_has_roles')->insert([
            'role_id' => $role->getKey(),
            'model_type' => $actor->getMorphClass(),
            'model_id' => $actor->getKey(),
        ]);
    });

    try {
        app(UpdateRolePermissionsAction::class)->execute($actor, $role, ['view_any_student']);
        $thrown = null;
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($role->fresh()->permissions()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| One lock order, everywhere
|--------------------------------------------------------------------------
*/

it('locks the super-admin role before the account on every role writer', function () {
    /*
     * DEADLOCK PREVENTION, ASSERTED AS AN ORDER.
     *
     * DeleteUserAction and DeactivateUserAction reach the invariant service
     * first, which locks the super_admin role row and only then touches the
     * account: role -> user. A role sync that locked the user first and the role
     * later would invert that, and two concurrent reductions against the same
     * super admin would each hold what the other waited for.
     *
     * Asserted for both the request path and the system path, because they are
     * separate writers and only one of them being right is a deadlock.
     */
    $writers = [
        'request path' => function (): void {
            $target = User::factory()->create(['is_active' => true]);
            app(SyncUserRolesAction::class)->execute($this->superAdmin, $target, ['staff']);
        },
        'system path' => function (): void {
            $target = User::factory()->create(['is_active' => true]);
            $this->system->syncRoles($target, ['staff']);
        },
    ];

    foreach ($writers as $label => $write) {
        $baseline = DB::transactionLevel();
        $statements = captureStatements();

        $write();

        $ordered = collect($statements)->values();

        $roleLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
            && str_contains($s['sql'], 'from `roles`'));

        $userLock = $ordered->search(fn (array $s): bool => str_contains($s['sql'], ' for update')
            && str_contains($s['sql'], 'from `users`'));

        expect($roleLock)->not->toBeFalse(
            "{$label}: the super-admin role row was never locked. ".describeStatements($statements),
        );

        expect($userLock)->not->toBeFalse(
            "{$label}: the account was never locked. ".describeStatements($statements),
        );

        expect($roleLock)->toBeLessThan(
            $userLock,
            "{$label}: the account was locked BEFORE the super-admin role, inverting the order "
            .'DeleteUserAction and DeactivateUserAction use. Two concurrent reductions against '
            .'the same super admin can now deadlock.',
        );

        /*
         * THE ORDER ONLY MEANS ANYTHING INSIDE A TRANSACTION.
         *
         * Two locks taken in the right sequence but on autocommit are released
         * the instant each statement finishes, so nothing is ever held long
         * enough to serialize against a concurrent writer — and the ordering
         * assertion above passes regardless. The depth is what proves both locks
         * are held together until the write commits.
         *
         * baseline + 1, not an absolute level: RefreshDatabase already holds a
         * transaction open, so the caller sits at 1 here and at 0 in production.
         * Asserting `=== 1` would pass for an Action that opens none.
         */
        foreach (['role' => $roleLock, 'account' => $userLock] as $what => $index) {
            expect($ordered[$index]['level'])->toBe(
                $baseline + 1,
                "{$label}: the {$what} lock ran at transaction level {$ordered[$index]['level']}, "
                .'expected '.($baseline + 1).' — one deeper than the caller. At the callers own '
                .'level the Action opened no transaction, so the lock releases immediately and the '
                .'ordering guarantees nothing. SQL: '.$ordered[$index]['sql'],
            );
        }
    }
});
