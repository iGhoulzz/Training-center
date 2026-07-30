<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\DeactivateUserAction;
use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Domain\Staff\Models\StaffProfile;
use App\Filament\Pages\PasswordChange;
use App\Http\Middleware\ForcePasswordChange;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\EditAction;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| P1-T15 security review — regression tests
|--------------------------------------------------------------------------
|
| One test per confirmed finding from the group 1 review. Every one of these was
| run and seen to FAIL before its fix existed; a security test that has never
| failed proves only that it runs.
|
| The findings are recorded with their evidence in
| docs/reviews/2026-07-29-phase-1-review.md.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->makeUser = function (string $role, array $attributes = []): User {
        $user = User::factory()->create([...['is_active' => true], ...$attributes]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };
});

/*
|--------------------------------------------------------------------------
| Finding 2 (Critical) — role identity must be resolved, not string-compared
|--------------------------------------------------------------------------
|
| MySQL's utf8mb4_unicode_ci resolves 'Super_Admin' to the super_admin row, while
| PHP's !== does not. The guard short-circuited to allow, and Spatie attached the
| genuine role. Authorization now resolves every submitted name to its persisted
| Role and compares by primary key.
*/

it('refuses a case-variant super_admin grant from an admin', function () {
    $admin = ($this->makeUser)('admin');
    $puppet = ($this->makeUser)('staff');

    expect(fn () => app(SyncUserRolesAction::class)->execute($admin, $puppet, ['Super_Admin']))
        ->toThrow(AuthorizationException::class);

    // The assertion that actually bites. An exception with the role attached
    // anyway would still be a total compromise.
    expect($puppet->fresh()->isSuperAdmin())->toBeFalse();
});

it('refuses every collation-equivalent spelling of the super-admin role', function (string $variant) {
    $admin = ($this->makeUser)('admin');
    $puppet = ($this->makeUser)('staff');

    expect(fn () => app(SyncUserRolesAction::class)->execute($admin, $puppet, [$variant]))
        ->toThrow(AuthorizationException::class);

    expect($puppet->fresh()->isSuperAdmin())->toBeFalse();
})->with(['SUPER_ADMIN', 'Super_admin', 'sUpEr_AdMiN']);

it('treats duplicate aliases of one role as a single role', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    app(SyncUserRolesAction::class)->execute($actor, $target, ['admin', 'ADMIN', 'Admin']);

    // One row, stored under its canonical name — not three, and not the alias.
    expect($target->fresh()->roles->pluck('name')->all())->toBe(['admin']);
});

it('rejects a role name that resolves to nothing', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    expect(fn () => app(SyncUserRolesAction::class)->execute($actor, $target, ['not_a_role']))
        ->toThrow(RoleDoesNotExist::class);

    expect($target->fresh()->roles->pluck('name')->all())->toBe(['staff']);
});

it('still lets a super admin grant super_admin by its canonical name', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    app(SyncUserRolesAction::class)->execute($actor, $target, ['super_admin']);

    expect($target->fresh()->isSuperAdmin())->toBeTrue();
});

it('records canonical role names in the activity log, never the submitted alias', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    app(SyncUserRolesAction::class)->execute($actor, $target, ['ADMIN']);

    $properties = DB::table('activity_log')
        ->where('event', 'roles_changed')
        ->orderByDesc('id')
        ->value('properties');

    // An audit trail that records what was typed rather than what was stored
    // misdescribes the change it exists to record.
    expect((string) $properties)->toContain('admin')->not->toContain('ADMIN');
});

it('does not report a role change when a case variant leaves the set unchanged', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('admin');

    $before = DB::table('activity_log')->count();

    app(SyncUserRolesAction::class)->execute($actor, $target, ['Admin']);

    // Same role, different spelling: nothing changed, so nothing is logged and
    // the invariant is not invoked. A name-based diff sees this as a removal
    // plus an addition and writes a change that never happened.
    expect(DB::table('activity_log')->count())->toBe($before)
        ->and($target->fresh()->roles->pluck('name')->all())->toBe(['admin']);
});

/*
|--------------------------------------------------------------------------
| Finding 1 (High) — Filament fails OPEN on a missing policy method
|--------------------------------------------------------------------------
|
| vendor/filament/filament/src/helpers.php:60-93 consults the Gate only when
| method_exists($policy, $action). Otherwise, with strict mode off (the default)
| and no Gate::before callback, it returns Response::allow(). Laravel's own Gate
| returns FALSE for the same missing method.
|
| These MUST be driven through the Filament path. A test written against
| Gate::allows() passes while the panel still permits the operation, which is the
| entire finding.
*/

it('refuses dormant Filament abilities through the panel, not merely through the gate', function (string $resource, string $model) {
    $actor = ($this->makeUser)('staff');
    $this->actingAs($actor);

    $record = $model::factory()->create();

    expect($resource::canDeleteAny())->toBeFalse("{$resource}::canDeleteAny()")
        ->and($resource::canReplicate($record))->toBeFalse("{$resource}::canReplicate()")
        ->and($resource::canReorder())->toBeFalse("{$resource}::canReorder()");
})->with([
    [UserResource::class, User::class],
    [StudentResource::class, Student::class],
    [BatchResource::class, Batch::class],
    [CourseResource::class, Course::class],
    [StaffProfileResource::class, StaffProfile::class],
]);

it('refuses restore and force-delete on soft-deletable models through the panel', function (string $resource, string $model) {
    $actor = ($this->makeUser)('staff');
    $this->actingAs($actor);

    $record = $model::factory()->create();
    $record->delete();

    expect($resource::canRestore($record))->toBeFalse("{$resource}::canRestore()")
        ->and($resource::canRestoreAny())->toBeFalse("{$resource}::canRestoreAny()")
        ->and($resource::canForceDelete($record))->toBeFalse("{$resource}::canForceDelete()")
        ->and($resource::canForceDeleteAny())->toBeFalse("{$resource}::canForceDeleteAny()");
})->with([
    [UserResource::class, User::class],
    [StudentResource::class, Student::class],
    [BatchResource::class, Batch::class],
    [StaffProfileResource::class, StaffProfile::class],
]);

it('refuses a super admin the dormant abilities too', function () {
    // Not an authorization question — these operations do not exist in phase 1.
    // Rank does not conjure them into being.
    $actor = ($this->makeUser)('super_admin');
    $this->actingAs($actor);

    $target = ($this->makeUser)('staff');
    $target->delete();

    expect(UserResource::canRestore($target))->toBeFalse()
        ->and(UserResource::canForceDelete($target))->toBeFalse()
        ->and(UserResource::canDeleteAny())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 4 (High) — the invariant branch was chosen from an unlocked read
|--------------------------------------------------------------------------
*/

it('locks the super-admin role row before deciding, even for an ordinary target', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    $statements = captureStatements();
    app(DeleteUserAction::class)->execute($actor, $target);

    // The branch decision reads whether the target is a super admin. Taking that
    // read outside the lock is the defect: a concurrent grant lands in the gap
    // and the deletion proceeds down the unprotected path.
    expect(locksOn($statements, 'roles'))->not->toBeEmpty(
        'DeleteUserAction did not lock the super-admin role row for a non-super-admin target. '
        .describeStatements($statements),
    );
});

it('locks the super-admin role row before deactivating an ordinary target', function () {
    $actor = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    $statements = captureStatements();
    app(DeactivateUserAction::class)->execute($actor, $target);

    expect(locksOn($statements, 'roles'))->not->toBeEmpty(
        'DeactivateUserAction did not lock the super-admin role row for a non-super-admin target. '
        .describeStatements($statements),
    );
});

it('re-reads the target under the lock rather than trusting the passed instance', function () {
    $actor = ($this->makeUser)('admin');
    $target = ($this->makeUser)('staff');

    // The concurrent grant: by the time the lock is taken, this account outranks
    // the actor. The instance handed to the Action still says "staff".
    $this->system->assignRoles(User::find($target->getKey()), 'super_admin');

    // Guard 1 — a non-super-admin may not delete a super admin. Authorizing the
    // caller's stale instance would pass, because that instance is not a super
    // admin; authorizing the locked row refuses.
    expect(fn () => app(DeleteUserAction::class)->execute($actor, $target))
        ->toThrow(AuthorizationException::class);

    expect(User::withTrashed()->find($target->getKey())->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 3 (Medium) — Shield's inline EditAction bypasses the Action
|--------------------------------------------------------------------------
*/

it('exposes no inline persisting edit action on the roles table', function () {
    $actor = ($this->makeUser)('super_admin');
    $this->actingAs($actor);

    $table = RoleResource::table(new Table(Livewire::new(ListRoles::class)));

    $recordActions = $table->getRecordActions();

    // The name is not the point — an edit affordance is fine and expected. What
    // must not exist is Filament's EditAction, which persists a modal with a
    // bare $record->update($data) and never reaches UpdateRolePermissionsAction.
    $persisting = collect($recordActions)
        ->filter(fn ($action): bool => $action instanceof EditAction)
        ->count();

    expect($persisting)->toBe(0, 'The inherited inline EditAction is still on the roles table.');

    // And the replacement must actually navigate to the app-owned page.
    $edit = collect($recordActions)
        ->first(fn ($action): bool => method_exists($action, 'getName') && $action->getName() === 'edit');

    // Bound to a record, as it is in a real table row: the URL closure is typed
    // on Role, and Filament evaluates it with null outside a row context.
    $role = Role::query()->where('name', 'admin')->firstOrFail();

    expect($edit)->not->toBeNull()
        // Shield's slug is inherited, so the path is /admin/shield/roles/{id}/edit
        // — the app-owned EditRole page, reached by navigation rather than by a
        // modal that would persist inline.
        ->and($edit->record($role)->getUrl())->toContain('/roles/'.$role->getKey().'/edit');

    // Shield also ships a DeleteBulkAction. RolePolicy::deleteAny() refuses it,
    // but a control that renders only in order to fail is one policy edit from
    // working.
    expect($table->getToolbarActions())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Finding 5 (Medium) — security middleware did not run on Livewire updates
|--------------------------------------------------------------------------
*/

it('keeps the password-change and session guards across livewire updates', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(ForcePasswordChange::class)
        ->and($persistent)->toContain(AuthenticateSession::class);
});

it('refuses an unrelated component to a user who must change their password', function () {
    $user = ($this->makeUser)('admin', ['must_change_password' => true]);

    $this->actingAs($user);

    // The stale-open-page scenario: the component was mounted before the reset,
    // and every interaction after it posts to Livewire's own route.
    $this->get('/admin/users')->assertRedirect('/admin/password-change');
});

it('still lets the password-change component itself submit', function () {
    // The other half. A guard that blocks the remedy as well as the problem
    // locks the user out entirely.
    $user = ($this->makeUser)('admin', [
        'must_change_password' => true,
        'password' => Hash::make('existing-password-1'),
    ]);

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'a-new-password-2',
            'password_confirmation' => 'a-new-password-2',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->must_change_password)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 6 (Medium) — self-service password change took no current password
|--------------------------------------------------------------------------
*/

it('refuses a password change that supplies no current password', function () {
    $user = ($this->makeUser)('admin', ['password' => Hash::make('existing-password-1')]);

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'password' => 'attacker-chosen-3',
            'password_confirmation' => 'attacker-chosen-3',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);

    expect(Hash::check('existing-password-1', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change that supplies the wrong current password', function () {
    // Session access must not be convertible into permanent credential
    // ownership: a stolen cookie should not be enough to lock the owner out.
    $user = ($this->makeUser)('admin', ['password' => Hash::make('existing-password-1')]);

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'not-the-password',
            'password' => 'attacker-chosen-3',
            'password_confirmation' => 'attacker-chosen-3',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);

    expect(Hash::check('existing-password-1', $user->fresh()->password))->toBeTrue();
});

it('accepts a password change with the correct current password', function () {
    $user = ($this->makeUser)('admin', ['password' => Hash::make('existing-password-1')]);

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'a-new-password-2',
            'password_confirmation' => 'a-new-password-2',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('a-new-password-2', $user->fresh()->password))->toBeTrue();
});
