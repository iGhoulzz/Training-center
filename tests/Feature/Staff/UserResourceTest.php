<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Staff account management UI (P1-T05).
 *
 * The architecture tests scan for known-bad code shapes and are the cheap early
 * warning, not the proof. These tests drive the REAL Livewire components and
 * assert database outcomes, so they hold no matter how a bypass is spelled: a
 * crafted role payload, a hidden action invoked directly, or a partial save left
 * behind by a refused hook.
 *
 * Roles are established here through the trusted system path — the request-path
 * Actions require an actor and would refuse a fixture.
 */
uses(RefreshDatabase::class);

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
| ResetUserPasswordAction
|--------------------------------------------------------------------------
*/

it('generates a temporary password and flags the user', function () {
    $actor = ($this->makeUser)('admin');

    $target = User::factory()->create(['must_change_password' => false]);
    $original = $target->password;

    // Two arguments: the Action authorizes the actor before touching anything.
    $plain = app(ResetUserPasswordAction::class)->execute($actor, $target);

    $target->refresh();

    expect($plain)->toHaveLength(16)
        ->and($target->must_change_password)->toBeTrue()
        ->and($target->password)->not->toBe($original)
        ->and(Hash::check($plain, $target->password))->toBeTrue();
});

it('refuses a password reset that the actor is not authorized to perform', function () {
    // Resetting a super admin's password would let a lesser actor log in as
    // them, defeating every rank guard. UserPolicy::resetPassword refuses it.
    $admin = ($this->makeUser)('admin');
    $superAdmin = ($this->makeUser)('super_admin');

    $originalPassword = $superAdmin->password;

    expect(fn () => app(ResetUserPasswordAction::class)->execute($admin, $superAdmin))
        ->toThrow(AuthorizationException::class);

    expect($superAdmin->fresh()->password)->toBe($originalPassword)
        ->and($superAdmin->fresh()->must_change_password)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Reaching the resource at all
|--------------------------------------------------------------------------
*/

it('denies staff access to the user list', function () {
    $staff = ($this->makeUser)('staff');

    $this->actingAs($staff)
        ->get('/admin/users')
        ->assertForbidden();
});

it('allows an admin to see the user list', function () {
    $admin = ($this->makeUser)('admin');

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The crafted payload — the field the UI never offers
|--------------------------------------------------------------------------
*/

it('refuses a crafted super_admin submission from an admin', function () {
    // The role dropdown is not the control. This submits a value straight into
    // the component's state, exactly as a hand-written Livewire payload would.
    // SyncUserRolesAction is what refuses it (guard 4: an admin holds no
    // assign_role ability; guard 1: only a super admin may grant super_admin).
    $admin = ($this->makeUser)('admin');
    $target = ($this->makeUser)('staff');

    $pivotRowsBefore = DB::table('model_has_roles')->where('model_id', $target->getKey())->count();

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['roles' => ['super_admin']])
        ->call('save')
        ->assertNotified(__('staff.save_refused_unauthorized'));

    expect($target->fresh()->roles()->pluck('name')->all())->toBe(['staff'])
        ->and($target->fresh()->isSuperAdmin())->toBeFalse()
        // Not merely "super_admin absent" — the role set is byte-for-byte
        // unchanged, so the refusal did not strip anything either.
        ->and(DB::table('model_has_roles')->where('model_id', $target->getKey())->count())
        ->toBe($pivotRowsBefore);
});

it('refuses a crafted super_admin submission on the create page', function () {
    // The create page is a second escalation surface: an admin who cannot
    // promote an existing account must not be able to conjure a super admin
    // from nothing either. The refusal must also take the half-created row with
    // it, or the admin ends up with an account they can log into later.
    $admin = ($this->makeUser)('admin');

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Smuggled Super Admin',
            'email' => 'smuggled@example.test',
            'locale' => 'en',
            'is_active' => true,
            'roles' => ['super_admin'],
        ])
        ->call('create')
        ->assertNotified(__('staff.save_refused_unauthorized'));

    expect(User::withTrashed()->where('email', 'smuggled@example.test')->exists())->toBeFalse()
        ->and(User::query()->role('super_admin')->count())->toBe(0);
});

it('rolls the attribute changes back when the role change is refused', function () {
    // Without $hasDatabaseTransactions = true this is a partial save: the rename
    // commits and only the role change is refused.
    $admin = ($this->makeUser)('admin');
    $target = ($this->makeUser)('staff', ['name' => 'Original Name']);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => 'Renamed By Admin',
            'roles' => ['staff', 'admin'],
        ])
        ->call('save')
        ->assertNotified(__('staff.save_refused_unauthorized'));

    expect($target->fresh()->name)->toBe('Original Name')
        ->and($target->fresh()->roles()->pluck('name')->all())->toBe(['staff']);
});

/*
|--------------------------------------------------------------------------
| The last active super admin
|--------------------------------------------------------------------------
*/

it('refuses to deactivate the last active super admin through the UI', function () {
    // The is_active toggle is dehydrated(false); DeactivateUserAction performs
    // the write and SuperAdminInvariantService refuses this one (guard 3, the
    // 422 business rule).
    $superAdmin = ($this->makeUser)('super_admin');

    expect(User::query()->role('super_admin')->where('is_active', true)->count())->toBe(1);

    Livewire::actingAs($superAdmin)
        ->test(EditUser::class, ['record' => $superAdmin->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertNotified(__('staff.save_refused_last_super_admin'));

    expect($superAdmin->fresh()->is_active)->toBeTrue()
        ->and(User::query()->role('super_admin')->where('is_active', true)->count())->toBe(1);
});

it('refuses to delete the last active super admin through the UI', function () {
    // Guard 3 on deletion is only reachable when the actor is not the target
    // (guard 2 blocks self-deletion), which means the acting super admin must
    // already be deactivated — a session that outlived its account. The
    // invariant must still hold on that path.
    $lastActive = ($this->makeUser)('super_admin');
    $deactivatedActor = ($this->makeUser)('super_admin', ['is_active' => false]);

    Livewire::actingAs($deactivatedActor)
        ->test(ListUsers::class)
        ->callTableAction('delete', $lastActive)
        ->assertNotified(__('staff.delete_refused_last_super_admin'));

    expect(User::find($lastActive->getKey()))->not->toBeNull()
        ->and($lastActive->fresh()->trashed())->toBeFalse()
        ->and(User::query()->role('super_admin')->where('is_active', true)->count())->toBe(1);
});

it('does not offer the delete action for a super admin self-deletion', function () {
    // Guard 2 refuses self-deletion in UserPolicy::delete, so the action is not
    // rendered. Hidden is not the same as absent: invoking it the way a crafted
    // payload would must also fail. Catch ONLY the visibility failure — a
    // broader catch would let this pass for the wrong reason.
    $superAdmin = ($this->makeUser)('super_admin');

    $component = Livewire::actingAs($superAdmin)->test(ListUsers::class);

    $component->assertTableActionHidden('delete', $superAdmin);

    try {
        $component->callTableAction('delete', $superAdmin);

        $this->fail('The delete action was invokable on the actor themselves; it must be refused.');
    } catch (ExpectationFailedException) {
        // Expected: Filament refused to call a non-visible action.
    }

    expect(User::find($superAdmin->getKey()))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Password reset through the table action
|--------------------------------------------------------------------------
*/

it('refuses a password reset of a super admin invoked from the table', function () {
    $admin = ($this->makeUser)('admin');
    $superAdmin = ($this->makeUser)('super_admin');

    $originalPassword = $superAdmin->password;

    $component = Livewire::actingAs($admin)->test(ListUsers::class);

    $component->assertTableActionHidden('resetPassword', $superAdmin);

    try {
        $component->callTableAction('resetPassword', $superAdmin);

        $this->fail('The reset-password action was invokable against a super admin.');
    } catch (ExpectationFailedException) {
        // Expected: the action is not visible to this actor.
    }

    expect($superAdmin->fresh()->password)->toBe($originalPassword)
        ->and($superAdmin->fresh()->must_change_password)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The controls that must still work
|--------------------------------------------------------------------------
*/

it('lets a super admin create an account with a temporary password and a role', function () {
    $superAdmin = ($this->makeUser)('super_admin');

    Livewire::actingAs($superAdmin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'New Instructor',
            'email' => 'new.instructor@example.test',
            'locale' => 'en',
            'is_active' => true,
            'roles' => ['staff'],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(__('staff.temp_password_generated'));

    $created = User::query()->where('email', 'new.instructor@example.test')->sole();

    expect($created->must_change_password)->toBeTrue()
        ->and($created->is_active)->toBeTrue()
        ->and($created->password)->not->toBeEmpty()
        ->and($created->roles()->pluck('name')->all())->toBe(['staff']);
});

it('lets a super admin assign a role through the edit page', function () {
    $superAdmin = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    Livewire::actingAs($superAdmin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => 'Promoted Person',
            'roles' => ['admin'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->name)->toBe('Promoted Person')
        ->and($target->fresh()->roles()->pluck('name')->all())->toBe(['admin']);
});

it('lets a super admin deactivate and reactivate an ordinary account', function () {
    $superAdmin = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    Livewire::actingAs($superAdmin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_active)->toBeFalse()
        // A save that never touches the roles field must leave it alone. This is
        // the silent-wipe failure mode: read the roles from $data instead of
        // getRawState() and dehydrated(false) hands you [], which
        // SyncUserRolesAction would faithfully apply by stripping every role.
        ->and($target->fresh()->roles()->pluck('name')->all())->toBe(['staff']);

    Livewire::actingAs($superAdmin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_active)->toBeTrue()
        ->and($target->fresh()->roles()->pluck('name')->all())->toBe(['staff']);
});

it('lets a super admin reset an ordinary account password from the table', function () {
    $superAdmin = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff', ['must_change_password' => false]);

    $originalPassword = $target->password;

    Livewire::actingAs($superAdmin)
        ->test(ListUsers::class)
        ->callTableAction('resetPassword', $target)
        ->assertNotified(__('staff.temp_password_generated'));

    expect($target->fresh()->password)->not->toBe($originalPassword)
        ->and($target->fresh()->must_change_password)->toBeTrue();
});

it('lets a super admin delete an ordinary account from the table', function () {
    $superAdmin = ($this->makeUser)('super_admin');
    $target = ($this->makeUser)('staff');

    Livewire::actingAs($superAdmin)
        ->test(ListUsers::class)
        ->callTableAction('delete', $target);

    expect(User::find($target->getKey()))->toBeNull()
        ->and(User::withTrashed()->find($target->getKey())?->trashed())->toBeTrue();
});
