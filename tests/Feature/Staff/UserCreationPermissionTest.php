<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Permission-model consistency for account creation (P1-T05b).
 *
 * An admin previously held create_user and reset_user_password but NOT
 * assign_role, which made creation internally inconsistent: choosing a role
 * rolled the whole creation back, and choosing none produced an account that
 * could reach no panel. Admins now hold assign_role, and guard 1 — not the
 * absence of the permission — is what stops them granting super_admin.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);
});

it('lets an admin create an account and give it a role', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($admin, 'admin');

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'New Instructor',
            'email' => 'instructor@example.test',
            'locale' => 'en',
            'roles' => ['staff'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'instructor@example.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->roles()->pluck('name')->all())->toBe(['staff'])
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->password)->not->toBeEmpty();
});

it('refuses an admin who crafts super_admin into the create form', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($admin, 'admin');

    $superAdminsBefore = User::role('super_admin')->count();

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'locale' => 'en',
            'roles' => ['super_admin'],
        ])
        ->call('create');

    // The create is transactional, so the refusal takes the half-made row too.
    expect(User::where('email', 'sneaky@example.test')->exists())->toBeFalse()
        ->and(User::role('super_admin')->count())->toBe($superAdminsBefore);
});

it('requires at least one role, so no unreachable account is created', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($admin, 'admin');

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Roleless',
            'email' => 'roleless@example.test',
            'locale' => 'en',
            'roles' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::where('email', 'roleless@example.test')->exists())->toBeFalse();
});

it('denies creation to an actor with create_user but not reset_user_password', function () {
    // Creating an account issues its first credential, so it is gated on the
    // permission to issue credentials as well as on create_user.
    $actor = User::factory()->create(['is_active' => true]);
    $partial = Role::findOrCreate('creator_only', 'web');
    $this->system->syncRolePermissions($partial, [
        'create_user', 'view_any_user', 'view_user', 'access_admin_panel', 'assign_role',
    ]);
    $this->system->assignRoles($actor, 'creator_only');

    expect($actor->can('create', User::class))->toBeFalse();
});

it('denies resetting an ordinary account to an actor without reset_user_password', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $partial = Role::findOrCreate('no_reset', 'web');
    $this->system->syncRolePermissions($partial, [
        'view_any_user', 'view_user', 'update_user', 'access_admin_panel',
    ]);
    $this->system->assignRoles($actor, 'no_reset');

    $target = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($target, 'staff');
    $original = $target->password;

    expect(fn () => app(ResetUserPasswordAction::class)->execute($actor, $target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->password)->toBe($original)
        ->and($target->fresh()->must_change_password)->toBeFalse();
});

it('refuses to strip the role from the last active super admin through the edit form', function () {
    // Guard 2 blocks self-edits and guard 1 blocks lesser actors, so the only
    // actor who can attempt this is another super admin — who must be inactive
    // for the target to be the LAST ACTIVE one.
    $target = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($target, 'super_admin');

    $actor = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($actor, 'super_admin');
    $actor->forceFill(['is_active' => false])->save();

    Livewire::actingAs($actor)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['roles' => ['staff']])
        ->call('save');

    expect($target->fresh()->isSuperAdmin())->toBeTrue()
        ->and(User::role('super_admin')->where('is_active', true)->count())->toBe(1);
});

it('registers a plain link action for create, not a Filament CreateAction', function () {
    // CreateAction keeps a mountable server-side create handler even when
    // ->url() is set, and that handler persists with a bare create(), skipping
    // CreateUser::afterCreate(). A plain Action has no handler to reach.
    $superAdmin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($superAdmin, 'super_admin');

    $action = Livewire::actingAs($superAdmin)
        ->test(ListUsers::class)
        ->instance()
        ->getAction('create');

    expect($action)->not->toBeNull()
        ->and($action)->not->toBeInstanceOf(CreateAction::class)
        ->and($action->getUrl())->toContain('/admin/users/create');
});

it('hides the create action from an actor who may not create', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $partial = Role::findOrCreate('viewer_only', 'web');
    $this->system->syncRolePermissions($partial, [
        'view_any_user', 'view_user', 'access_admin_panel',
    ]);
    $this->system->assignRoles($actor, 'viewer_only');

    $action = Livewire::actingAs($actor)
        ->test(ListUsers::class)
        ->instance()
        ->getAction('create');

    expect($action?->isVisible() ?? false)->toBeFalse();
});

it('routes creating and editing to full pages', function () {
    $superAdmin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($superAdmin, 'super_admin');

    $target = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($target, 'staff');

    $this->actingAs($superAdmin)->get('/admin/users/create')->assertSuccessful();
    $this->actingAs($superAdmin)
        ->get('/admin/users/'.$target->getKey().'/edit')
        ->assertSuccessful();
});
