<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\ActivateUserAction;
use App\Domain\Staff\Actions\DeactivateUserAction;
use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Guard 5 and the own-password rule (P4-T43, after the incident of 2026-11-01).
 *
 * One super admin reset his OWN password from the list (AuthenticateSession ended
 * his session before the one-time password was shown) and then deleted the other
 * super admin. The owner's decisions, as narrowed on 2026-11-02, each tested both
 * ways here:
 *
 *   - "Reset password" never applies to one's own account; "Change password" does.
 *   - A super admin cannot DELETE or DEACTIVATE another super admin from the
 *     panel. Creating, editing, demoting and resetting the password of one is
 *     unchanged, and a super admin still manages everyone below them.
 *
 * Actors who stand for a PERMISSION hold exactly the permissions named, never
 * super_admin (which holds every one and would pass whichever ability the code
 * checked). The super admins in this file are there because the rule under test is
 * about RANK, which no permission can express.
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

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->superAdminA = ($this->makeUser)('super_admin');
    $this->superAdminB = ($this->makeUser)('super_admin');
    $this->admin = ($this->makeUser)('admin');
    $this->staff = ($this->makeUser)('staff');
});

/** The permission each policy ability needs, written out rather than read from the policy. */
dataset('guarded abilities', [
    'update' => ['update', 'update_user'],
    'delete' => ['delete', 'delete_user'],
    'resetPassword' => ['resetPassword', 'reset_user_password'],
    'deactivate' => ['deactivate', 'update_user'],
]);

/*
|--------------------------------------------------------------------------
| The policy
|--------------------------------------------------------------------------
*/

it('refuses a super admin deleting or deactivating another super admin: :dataset', function (string $ability) {
    expect($this->superAdminA->can($ability, $this->superAdminB))->toBeFalse()
        ->and($this->superAdminB->can($ability, $this->superAdminA))->toBeFalse();
})->with(['delete', 'deactivate']);

it('still lets a super admin edit or reset the password of another super admin: :dataset', function (string $ability, string $permission) {
    $oneShort = ($this->userWith)('view_any_user', 'view_user');
    $holder = ($this->userWith)($permission);

    expect($this->superAdminA->can($ability, $this->superAdminB))->toBeTrue()
        // One permission short, and one rank short (guard 1): refused.
        ->and($oneShort->can($ability, $this->superAdminB))->toBeFalse()
        ->and($holder->can($ability, $this->superAdminB))->toBeFalse();
})->with([
    'update' => ['update', 'update_user'],
    'resetPassword' => ['resetPassword', 'reset_user_password'],
]);

it('still lets a super admin use every guarded ability on an ordinary account: :dataset', function (string $ability) {
    expect($this->superAdminA->can($ability, $this->admin))->toBeTrue()
        ->and($this->superAdminA->can($ability, $this->staff))->toBeTrue();
})->with(['update', 'delete', 'resetPassword', 'deactivate']);

it('allows exactly the holder of the permission to act on an ordinary account, and nobody one permission short: :dataset', function (string $ability, string $permission) {
    $holder = ($this->userWith)($permission);
    $oneShort = ($this->userWith)('view_any_user', 'view_user');

    expect($holder->can($ability, $this->staff))->toBeTrue()
        ->and($oneShort->can($ability, $this->staff))->toBeFalse();
})->with('guarded abilities');

it('refuses the holder of the permission any ability on a super admin: :dataset', function (string $ability, string $permission) {
    $holder = ($this->userWith)($permission);

    expect($holder->can($ability, $this->superAdminA))->toBeFalse();
})->with('guarded abilities');

it('lets a super admin update their own account but neither delete it nor reset its password', function () {
    expect($this->superAdminA->can('update', $this->superAdminA))->toBeTrue()
        ->and($this->superAdminA->can('delete', $this->superAdminA))->toBeFalse()
        ->and($this->superAdminA->can('resetPassword', $this->superAdminA))->toBeFalse();
});

it('refuses resetting your own password even to the holder of exactly reset_user_password', function () {
    $holder = ($this->userWith)('reset_user_password');
    $oneShort = ($this->userWith)('update_user');

    // The permission is enough for somebody else's account...
    expect($holder->can('resetPassword', $this->staff))->toBeTrue()
        // ...and never for one's own.
        ->and($holder->can('resetPassword', $holder))->toBeFalse()
        ->and($oneShort->can('resetPassword', $this->staff))->toBeFalse();
});

it('treats an account holding both admin and super_admin as a super admin target', function () {
    $dual = User::factory()->create();
    $this->system->assignRoles($dual, ['admin', 'super_admin']);

    expect($this->superAdminA->can('delete', $dual))->toBeFalse()
        ->and($this->superAdminA->can('deactivate', $dual))->toBeFalse()
        ->and($this->superAdminA->can('resetPassword', $dual))->toBeTrue()
        ->and($this->superAdminA->can('update', $dual))->toBeTrue()
        ->and($this->admin->can('update', $dual))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The Actions - the boundary, authorized against the locked row
|--------------------------------------------------------------------------
*/

it('refuses a super admin deleting another super admin and changes nothing', function () {
    expect(fn () => app(DeleteUserAction::class)->execute($this->superAdminA, $this->superAdminB))
        ->toThrow(AuthorizationException::class);

    expect(User::find($this->superAdminB->id))->not->toBeNull();
});

it('still lets a super admin delete an admin', function () {
    app(DeleteUserAction::class)->execute($this->superAdminA, $this->admin);

    expect(User::find($this->admin->id))->toBeNull();
});

it('refuses a super admin deactivating another super admin and changes nothing', function () {
    expect(fn () => app(DeactivateUserAction::class)->execute($this->superAdminA, $this->superAdminB))
        ->toThrow(AuthorizationException::class);

    expect($this->superAdminB->fresh()->is_active)->toBeTrue();
});

it('still lets a super admin deactivate an admin', function () {
    app(DeactivateUserAction::class)->execute($this->superAdminA, $this->admin);

    expect($this->admin->fresh()->is_active)->toBeFalse();
});

it('still lets a super admin reactivate another super admin, since that only adds to the population', function () {
    $this->superAdminB->forceFill(['is_active' => false])->save();

    app(ActivateUserAction::class)->execute($this->superAdminA, $this->superAdminB);

    expect($this->superAdminB->fresh()->is_active)->toBeTrue();
});

it('still lets a super admin reactivate a deactivated admin, and refuses one without update_user', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $oneShort = ($this->userWith)('view_any_user');

    expect(fn () => app(ActivateUserAction::class)->execute($oneShort, $this->admin))
        ->toThrow(AuthorizationException::class);
    expect($this->admin->fresh()->is_active)->toBeFalse();

    app(ActivateUserAction::class)->execute($this->superAdminA, $this->admin);

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('decides ActivateUserAction on the locked row, not on the instance the caller holds', function () {
    // The caller's copy is stale twice over: it says the account is already active
    // (an early return on it would skip the activation) and says it is an ordinary
    // admin. The database says it is a DEACTIVATED SUPER ADMIN. An admin holding
    // update_user must be refused on the database's rank, and a super admin must
    // reactivate on the database's is_active.
    $target = ($this->makeUser)('admin');
    $stale = User::query()->findOrFail($target->getKey());
    $stale->loadMissing('roles');

    $this->system->syncRoles($target, ['super_admin']);
    $target->forceFill(['is_active' => false])->save();

    expect($stale->is_active)->toBeTrue()
        ->and($stale->roles->pluck('name')->all())->toBe(['admin']);

    expect(fn () => app(ActivateUserAction::class)->execute($this->admin, $stale))
        ->toThrow(AuthorizationException::class);
    expect($target->fresh()->is_active)->toBeFalse();

    app(ActivateUserAction::class)->execute($this->superAdminA, $stale);

    expect($target->fresh()->is_active)->toBeTrue();
});

it('lets a super admin reset another super admin password, and refuses an actor without reset_user_password', function () {
    $oneShort = ($this->userWith)('update_user');
    $original = $this->superAdminB->password;

    expect(fn () => app(ResetUserPasswordAction::class)->execute($oneShort, $this->superAdminB))
        ->toThrow(AuthorizationException::class);
    expect($this->superAdminB->fresh()->password)->toBe($original);

    $plain = app(ResetUserPasswordAction::class)->execute($this->superAdminA, $this->superAdminB);

    expect($plain)->toHaveLength(16)
        ->and($this->superAdminB->fresh()->must_change_password)->toBeTrue()
        ->and($this->superAdminB->fresh()->password)->not->toBe($original);
});

it('refuses resetting your own password through the Action and leaves it untouched', function () {
    $original = $this->superAdminA->password;
    $holder = ($this->userWith)('reset_user_password');
    $holderOriginal = $holder->password;

    expect(fn () => app(ResetUserPasswordAction::class)->execute($this->superAdminA, $this->superAdminA))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ResetUserPasswordAction::class)->execute($holder, $holder))
        ->toThrow(AuthorizationException::class);

    expect($this->superAdminA->fresh()->password)->toBe($original)
        ->and($holder->fresh()->password)->toBe($holderOriginal)
        ->and($holder->fresh()->must_change_password)->toBeFalse();
});

it('still lets a super admin reset an admin password, and refuses an actor without reset_user_password', function () {
    $oneShort = ($this->userWith)('update_user');

    expect(fn () => app(ResetUserPasswordAction::class)->execute($oneShort, $this->admin))
        ->toThrow(AuthorizationException::class);

    $plain = app(ResetUserPasswordAction::class)->execute($this->superAdminA, $this->admin);

    expect($plain)->toHaveLength(16)
        ->and($this->admin->fresh()->must_change_password)->toBeTrue();
});

it('lets a super admin demote another super admin while another active one remains', function () {
    app(SyncUserRolesAction::class)->execute($this->superAdminA, $this->superAdminB, ['admin']);

    expect($this->superAdminB->fresh()->roles()->pluck('name')->all())->toBe(['admin'])
        ->and($this->superAdminA->fresh()->isSuperAdmin())->toBeTrue();
});

it('still refuses demoting the LAST active super admin (guard 3), and an actor without assign_role', function () {
    // A stale session of a deactivated super admin acts on the only active one.
    $lastActive = $this->superAdminB;
    $this->superAdminA->forceFill(['is_active' => false])->save();
    $oneShort = ($this->userWith)('update_user');

    expect(fn () => app(SyncUserRolesAction::class)->execute($oneShort, $lastActive, ['admin']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SyncUserRolesAction::class)->execute($this->superAdminA, $lastActive, ['admin']))
        ->toThrow(LastSuperAdminException::class);

    expect($lastActive->fresh()->roles()->pluck('name')->all())->toBe(['super_admin']);
});

it('still lets a super admin change the roles of an ordinary account, and refuses an actor without assign_role', function () {
    $oneShort = ($this->userWith)('update_user');

    expect(fn () => app(SyncUserRolesAction::class)->execute($oneShort, $this->staff, ['admin']))
        ->toThrow(AuthorizationException::class);
    expect($this->staff->fresh()->hasRole('staff'))->toBeTrue();

    app(SyncUserRolesAction::class)->execute($this->superAdminA, $this->staff, ['admin']);

    expect($this->staff->fresh()->roles()->pluck('name')->all())->toBe(['admin']);
});

it('refuses an admin touching any super admin through every Action (guard 1 stays)', function () {
    expect(fn () => app(DeleteUserAction::class)->execute($this->admin, $this->superAdminA))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeactivateUserAction::class)->execute($this->admin, $this->superAdminA))->toThrow(AuthorizationException::class);
    expect(fn () => app(ResetUserPasswordAction::class)->execute($this->admin, $this->superAdminA))->toThrow(AuthorizationException::class);
    expect(fn () => app(SyncUserRolesAction::class)->execute($this->admin, $this->superAdminA, ['admin']))->toThrow(AuthorizationException::class);

    expect(User::find($this->superAdminA->id))->not->toBeNull()
        ->and($this->superAdminA->fresh()->is_active)->toBeTrue()
        ->and($this->superAdminA->fresh()->isSuperAdmin())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Users & roles list
|--------------------------------------------------------------------------
*/

it('shows a super admin no Delete on another super admin, and Delete and Reset password on an admin', function () {
    $component = Livewire::actingAs($this->superAdminA)->test(ListUsers::class);

    $component->assertTableActionHidden('delete', $this->superAdminB)
        ->assertTableActionVisible('resetPassword', $this->superAdminB)
        ->assertTableActionHidden('changePassword', $this->superAdminB)
        ->assertTableActionVisible('delete', $this->admin)
        ->assertTableActionVisible('resetPassword', $this->admin)
        ->assertTableActionHidden('changePassword', $this->admin);
});

it('replaces Reset password with Change password on your own row, for a super admin', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(ListUsers::class)
        ->assertTableActionHidden('resetPassword', $this->superAdminA)
        ->assertTableActionHidden('delete', $this->superAdminA)
        ->assertTableActionVisible('changePassword', $this->superAdminA)
        ->assertTableActionHasUrl('changePassword', PasswordChange::getUrl(), $this->superAdminA);
});

it('replaces Reset password with Change password on your own row, for a holder of exactly reset_user_password', function () {
    $holder = ($this->userWith)('access_admin_panel', 'view_any_user', 'view_user', 'reset_user_password');
    $oneShort = ($this->userWith)('access_admin_panel', 'view_any_user', 'view_user');

    Livewire::actingAs($holder)
        ->test(ListUsers::class)
        ->assertTableActionVisible('resetPassword', $this->staff)
        ->assertTableActionHidden('resetPassword', $holder)
        ->assertTableActionVisible('changePassword', $holder)
        ->assertTableActionHidden('changePassword', $this->staff);

    // One permission short: no reset anywhere, but one's own password is still one's own.
    Livewire::actingAs($oneShort)
        ->test(ListUsers::class)
        ->assertTableActionHidden('resetPassword', $this->staff)
        ->assertTableActionHidden('resetPassword', $oneShort)
        ->assertTableActionVisible('changePassword', $oneShort);
});

it('does not run a hidden Delete on another super admin, or Reset password on your own row, when invoked directly', function () {
    $component = Livewire::actingAs($this->superAdminA)->test(ListUsers::class);

    expect(fn () => $component->callTableAction('delete', $this->superAdminB))
        ->toThrow(ExpectationFailedException::class);
    expect(fn () => $component->callTableAction('resetPassword', $this->superAdminA))
        ->toThrow(ExpectationFailedException::class);

    expect(User::find($this->superAdminB->id))->not->toBeNull()
        ->and(User::find($this->superAdminA->id))->not->toBeNull();
});

it('resets another super admin password from the list and shows the one-time password', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(ListUsers::class)
        ->callTableAction('resetPassword', $this->superAdminB)
        ->assertNotified(__('staff.temp_password_generated'));

    expect($this->superAdminB->fresh()->must_change_password)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The edit page - where deactivation is reachable (the Active toggle)
|--------------------------------------------------------------------------
*/

it('opens the edit page of another super admin, with the Active toggle disabled and explained', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminB->getKey()])
        ->assertSuccessful()
        ->assertFormFieldIsDisabled('is_active')
        ->assertSee('A super admin cannot be deactivated here.');

    $this->actingAs($this->superAdminA)
        ->get('/admin/users/'.$this->superAdminB->getKey().'/edit')
        ->assertSuccessful();

    // The control for an ordinary account stays live and quiet.
    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->admin->getKey()])
        ->assertFormFieldIsEnabled('is_active')
        ->assertDontSee('A super admin cannot be deactivated here.');
});

it('refuses a crafted save that deactivates another super admin, and rolls the whole save back', function () {
    $this->superAdminB->forceFill(['name' => 'Original Name'])->save();

    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminB->getKey()])
        ->fillForm(['name' => 'Renamed In The Same Payload', 'is_active' => false])
        ->call('save')
        ->assertNotified(__('staff.save_refused_unauthorized'));

    expect($this->superAdminB->fresh()->is_active)->toBeTrue()
        ->and($this->superAdminB->fresh()->name)->toBe('Original Name');
});

it('refuses one save that demotes and deactivates another super admin together, and keeps both', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminB->getKey()])
        ->fillForm(['roles' => ['admin'], 'is_active' => false])
        ->call('save')
        ->assertNotified(__('staff.save_refused_unauthorized'));

    $fresh = $this->superAdminB->fresh();

    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->isSuperAdmin())->toBeTrue();
});

it('lets a super admin edit another super admin and demote them from the edit page', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminB->getKey()])
        ->fillForm(['name' => 'Renamed Peer', 'roles' => ['admin']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->superAdminB->fresh()->name)->toBe('Renamed Peer')
        ->and($this->superAdminB->fresh()->roles()->pluck('name')->all())->toBe(['admin']);
});

it('lets a super admin switch a deactivated super admin back on from the edit page', function () {
    $this->superAdminB->forceFill(['is_active' => false])->save();

    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminB->getKey()])
        ->assertFormFieldIsEnabled('is_active')
        ->fillForm(['is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->superAdminB->fresh()->is_active)->toBeTrue();
});

it('lets a super admin edit their own profile and keeps their roles and active state', function () {
    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->superAdminA->getKey()])
        ->fillForm(['name' => 'Renamed Super Admin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->superAdminA->fresh()->name)->toBe('Renamed Super Admin')
        ->and($this->superAdminA->fresh()->isSuperAdmin())->toBeTrue()
        ->and($this->superAdminA->fresh()->is_active)->toBeTrue();
});

it('lets a super admin deactivate an admin from the Active toggle, and refuses an editor without update_user', function () {
    $oneShort = ($this->userWith)('access_admin_panel', 'view_any_user', 'view_user');

    Livewire::actingAs($oneShort)
        ->test(EditUser::class, ['record' => $this->admin->getKey()])
        ->assertForbidden();

    Livewire::actingAs($this->superAdminA)
        ->test(EditUser::class, ['record' => $this->admin->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->admin->fresh()->is_active)->toBeFalse();
});

it('still refuses an admin the edit page of a super admin (guard 1)', function () {
    Livewire::actingAs($this->admin)
        ->test(EditUser::class, ['record' => $this->superAdminA->getKey()])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Creating a super admin
|--------------------------------------------------------------------------
*/

it('creates a super admin from the create page and shows the one-time password', function () {
    // The reviewer's P2: the save rolled back with "needs the password-reset
    // permission", because the new account was a super admin by the time its
    // password was issued and the policy refused a super admin resetting one.
    Livewire::actingAs($this->superAdminA)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Third Super Admin',
            'email' => 'third@example.test',
            'locale' => 'en',
            'is_active' => true,
            'roles' => ['super_admin'],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(__('staff.temp_password_generated'));

    $created = User::query()->where('email', 'third@example.test')->firstOrFail();

    expect($created->isSuperAdmin())->toBeTrue()
        ->and($created->must_change_password)->toBeTrue();
});

it('refuses an admin creating a super admin, and a creator without reset_user_password creating anyone', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateUser::class)
        ->fillForm(['name' => 'X', 'email' => 'x@example.test', 'locale' => 'en', 'is_active' => true, 'roles' => ['super_admin']])
        ->call('create')
        ->assertHasFormErrors(['roles.0']);

    $oneShort = ($this->userWith)('access_admin_panel', 'view_any_user', 'view_user', 'create_user', 'assign_role');

    Livewire::actingAs($oneShort)
        ->test(CreateUser::class)
        ->assertForbidden();

    expect(User::query()->whereIn('email', ['x@example.test'])->exists())->toBeFalse();
});
