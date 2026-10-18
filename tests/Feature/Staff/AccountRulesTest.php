<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\DeactivateUserAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\CreateStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\EditStaffProfile;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Models\StaffProfile;
use App\Http\Middleware\LogOutInactiveUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Livewire\Notifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * The account rules the owner settled on 2026-10-17 (P4-T33): nobody deactivates
 * their own account, an open session of a deactivated account is ended with a
 * message rather than a 403, and the pickers offer only what a save would accept.
 *
 * Roles are established through the trusted system path. Actors who stand for a
 * PERMISSION hold exactly the permissions named, never super_admin, which holds
 * every permission and would pass whichever ability the code happened to check.
 * The two places that name a super admin do so because the rule under test is
 * about RANK (guard 1: only a super admin grants super_admin), which no
 * permission can express.
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

    $this->deactivate = app(DeactivateUserAction::class);
});

/*
|--------------------------------------------------------------------------
| No self-deactivation (QA Q3 / P3-13)
|--------------------------------------------------------------------------
*/

it('refuses an actor deactivating their own account, with the rule\'s message', function () {
    // update_user is the only permission the Action's gate asks for, so this
    // actor is fully entitled to deactivate OTHER people. The refusal is about
    // the target being themselves, not about what they may do.
    $actor = ($this->userWith)('update_user');

    try {
        $this->deactivate->execute($actor, $actor);

        $this->fail('Deactivating your own account was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toBe(['is_active' => [
            'You cannot deactivate your own account. Ask another administrator to do it.',
        ]]);
    }

    expect($actor->fresh()->is_active)->toBeTrue();
});

it('still lets an actor with update_user deactivate somebody else', function () {
    $actor = ($this->userWith)('update_user');
    $target = ($this->makeUser)('staff');

    $this->deactivate->execute($actor, $target);

    expect($target->fresh()->is_active)->toBeFalse()
        ->and($actor->fresh()->is_active)->toBeTrue();
});

it('still refuses an actor without update_user, so the rule did not loosen authorization', function () {
    // The pair for the test above: one permission fewer.
    $actor = ($this->userWith)('view_any_user');
    $target = ($this->makeUser)('staff');

    expect(fn () => $this->deactivate->execute($actor, $target))->toThrow(AuthorizationException::class);

    expect($target->fresh()->is_active)->toBeTrue();
});

it('keeps the last-super-admin guard: a stale session of a deactivated super admin cannot deactivate the last active one', function () {
    // Self-deactivation can no longer reach the invariant, so it is exercised the
    // way it can still be reached: an actor whose account was deactivated while
    // their session stayed open, acting on the one active super admin left.
    $lastActive = ($this->makeUser)('super_admin');
    $staleActor = ($this->makeUser)('super_admin', ['is_active' => false]);

    expect(fn () => $this->deactivate->execute($staleActor, $lastActive))
        ->toThrow(LastSuperAdminException::class);

    expect($lastActive->fresh()->is_active)->toBeTrue();
});

it('disables the active toggle on your own record and says why', function () {
    $admin = ($this->makeUser)('admin');
    $other = ($this->makeUser)('staff');

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $admin->getKey()])
        ->assertFormFieldIsDisabled('is_active')
        ->assertSee('You cannot deactivate your own account.');

    // The same page for somebody else's record keeps the control live and quiet.
    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $other->getKey()])
        ->assertFormFieldIsEnabled('is_active')
        ->assertDontSee('You cannot deactivate your own account.');
});

it('answers a forged own-record deactivation with the refusal toast and saves nothing', function () {
    // The toggle is disabled, but a hand-written Livewire payload can still set
    // the state. DeactivateUserAction refuses, and the page reports it through the
    // same refuseSave() path as every other refusal - a toast, not a 403 modal -
    // and rolls the whole save back, so the rename in the same payload is undone.
    $admin = ($this->makeUser)('admin', ['name' => 'Original Name']);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['name' => 'Renamed In The Same Payload', 'is_active' => false])
        ->call('save')
        ->assertNotified(__('staff.cannot_deactivate_self'));

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($admin->fresh()->name)->toBe('Original Name');
});

it('still deactivates and reactivates somebody else through the page', function () {
    $admin = ($this->makeUser)('admin');
    $target = ($this->makeUser)('staff');

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_active)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| An open session of an account deactivated meanwhile (P3-14)
|--------------------------------------------------------------------------
|
| Real HTTP, for the reason LivewirePersistentGuardTest gives: Livewire::test()
| never routes, so it skips every persistent middleware and would pass whether or
| not LogOutInactiveUser were registered there.
|
| The account is deactivated on the SAME instance the guard holds. actingAs() puts
| that instance in the guard, so a write through any other instance would leave
| the guard's copy active and the middleware would have nothing to see - unlike
| a real request, which loads the account afresh.
*/

/**
 * One account and the page it has open, per panel.
 *
 * @return array{user: User, guard: string, page: string, component: string, login: string}
 */
function openSessionScenario(string $panel, Closure $makeUser): array
{
    if ($panel === 'admin') {
        return [
            'user' => $makeUser('admin'),
            'guard' => 'web',
            'page' => '/admin/users',
            'component' => 'App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers',
            'login' => '/admin/login?reason=deactivated',
        ];
    }

    $student = $makeUser('student');
    Student::factory()->for($student)->create();

    return [
        'user' => $student,
        'guard' => 'student',
        'page' => '/portal/password-change',
        'component' => Notifications::class,
        'login' => '/portal/login?reason=deactivated',
    ];
}

beforeEach(function () {
    /**
     * Load a page and return the payload a browser would post back to Livewire.
     * Same extraction as LivewirePersistentGuardTest: the component is selected
     * by name, because the first snapshot on a panel page is the topbar's.
     */
    $this->openPageComponent = function (string $url, string $componentName): array {
        $html = (string) $this->get($url)->assertSuccessful()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        $snapshot = null;

        foreach ($matches[1] as $raw) {
            $candidate = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($candidate, true)['memo']['name'] ?? null) === $componentName) {
                $snapshot = $candidate;

                break;
            }
        }

        expect($snapshot)->not->toBeNull("No rendered snapshot for {$componentName} on {$url}.");

        return ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]];
    };

    $this->interact = fn (array $payload) => $this
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), $payload);
});

it('lets a full-page request through while the account is active', function (string $panel) {
    // The control: without it a redirect below could be any refusal at all.
    $scenario = openSessionScenario($panel, $this->makeUser);

    $this->actingAs($scenario['user'], $scenario['guard'])
        ->get($scenario['page'])
        ->assertSuccessful();
})->with(['admin', 'portal']);

it('sorts the sign-out middleware ahead of Authenticate on a route of each panel', function (string $routeName) {
    // Laravel re-sorts route middleware by a priority list and a plain middleware
    // listed FIRST in authMiddleware() still ends up behind Authenticate, which
    // answers a deactivated account with abort(403) before this class can run.
    $route = Route::getRoutes()->getByName($routeName);
    $middleware = app('router')->gatherRouteMiddleware($route);

    $signOut = array_search(LogOutInactiveUser::class, $middleware, true);
    $authenticate = array_search(Authenticate::class, $middleware, true);

    expect($signOut)->toBeInt('LogOutInactiveUser is not on the route.')
        ->and($authenticate)->toBeInt()
        ->and($signOut)->toBeLessThan($authenticate);
})->with(['filament.admin.resources.users.index', 'filament.student.pages.password-change']);

it('ends the session on the next full-page request after the account is deactivated', function (string $panel) {
    $scenario = openSessionScenario($panel, $this->makeUser);
    $guard = $scenario['guard'];

    $this->withSession(['canary' => 'present before'])->actingAs($scenario['user'], $guard);
    $this->get($scenario['page'])->assertSuccessful();
    $tokenBefore = session()->token();

    $scenario['user']->forceFill(['is_active' => false])->save();

    // A redirect to that panel's login with the reason - not Filament's 403 page.
    $this->get($scenario['page'])->assertRedirect($scenario['login']);

    $this->assertGuest($guard);
    expect(session()->has('canary'))->toBeFalse('The session was not invalidated.')
        ->and(session()->token())->not->toBeNull()->not->toBe($tokenBefore);

    // And it stays ended: the next request meets the ordinary login redirect.
    $this->get($scenario['page'])->assertRedirect(str_replace('?reason=deactivated', '', $scenario['login']));
})->with(['admin', 'portal']);

it('lets a livewire request through while the account is active', function (string $panel) {
    // The control for the test below, and a SEPARATE test on purpose: Livewire
    // applies persistent middleware once per route per request cycle and the test
    // harness never ends the cycle, so a second interaction in the same test would
    // skip the middleware and prove nothing.
    $scenario = openSessionScenario($panel, $this->makeUser);

    $this->actingAs($scenario['user'], $scenario['guard']);
    $payload = ($this->openPageComponent)($scenario['page'], $scenario['component']);

    ($this->interact)($payload)->assertSuccessful();
})->with(['admin', 'portal']);

it('ends the session on the next livewire request after the account is deactivated', function (string $panel) {
    $scenario = openSessionScenario($panel, $this->makeUser);
    $guard = $scenario['guard'];

    $this->actingAs($scenario['user'], $guard);
    $payload = ($this->openPageComponent)($scenario['page'], $scenario['component']);

    $scenario['user']->forceFill(['is_active' => false])->save();

    // A redirect Livewire's client follows, not a 403 error modal on a page the
    // person has been using a moment ago.
    ($this->interact)($payload)->assertRedirect($scenario['login']);

    $this->assertGuest($guard);
})->with(['admin', 'portal']);

it('does not execute a livewire mutation from a page opened before the account was deactivated', function () {
    $actor = ($this->makeUser)('admin');
    $victim = ($this->makeUser)('staff');

    $this->actingAs($actor);
    $payload = ($this->openPageComponent)('/admin/users', 'App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers');

    $payload['components'][0]['calls'] = [[
        'path' => '',
        'method' => 'mountAction',
        'params' => ['delete', ['table' => true, 'recordKey' => (string) $victim->getKey()]],
    ]];

    $actor->forceFill(['is_active' => false])->save();

    ($this->interact)($payload)->assertRedirect('/admin/login?reason=deactivated');

    expect(User::withTrashed()->find($victim->getKey())->trashed())->toBeFalse();
});

it('shows the message on the login page, and only when the redirect asked for it', function () {
    $this->get('/admin/login?reason=deactivated')
        ->assertSuccessful()
        ->assertSee('Your account has been deactivated, so you have been signed out.');

    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertDontSee('Your account has been deactivated');

    // A value nobody sends is not a way to put words on the page.
    $this->get('/admin/login?reason=anything-else')
        ->assertSuccessful()
        ->assertDontSee('Your account has been deactivated');

    $this->get('/portal/login?reason=deactivated')
        ->assertSuccessful()
        ->assertSee('Your account has been deactivated, so you have been signed out.');
});

it('keeps the message after the browser itself followed the livewire redirect', function () {
    // Livewire's client does not stop at the 302: fetch() follows it on its own,
    // so the login page is requested once BY THE FETCH, then again when the
    // browser navigates to the final URL. A message flashed into the session is
    // consumed by the first of those and the person never sees it, which is why
    // the reason travels in the URL.
    $user = ($this->makeUser)('admin');

    $this->actingAs($user);
    $payload = ($this->openPageComponent)('/admin/users', 'App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers');

    $user->forceFill(['is_active' => false])->save();

    $location = ($this->interact)($payload)->assertRedirect()->headers->get('Location');

    $this->withHeaders(['X-Livewire' => 'true'])->get($location)->assertSuccessful();

    $this->get($location)
        ->assertSuccessful()
        ->assertSee('Your account has been deactivated, so you have been signed out.');
});

/*
|--------------------------------------------------------------------------
| Pickers that offer what a save accepts (P3-5)
|--------------------------------------------------------------------------
*/

/**
 * The role names the create form's picker offers.
 *
 * @return array<int, string>
 */
function offeredRoles(User $actor): array
{
    $offered = [];

    Livewire::actingAs($actor)
        ->test(CreateUser::class)
        ->assertFormFieldExists('roles', function (Select $field) use (&$offered): bool {
            $offered = array_keys($field->getOptions());

            return true;
        });

    sort($offered);

    return $offered;
}

it('offers an admin neither super_admin nor student', function () {
    // The seeded roles are super_admin, admin, staff, student. An admin holds
    // assign_role, may not grant super_admin (guard 1), and portal accounts are
    // issued by IssuePortalCredentialAction.
    expect(offeredRoles(($this->makeUser)('admin')))->toBe(['admin', 'staff']);
});

it('offers a super admin super_admin but still not student', function () {
    expect(offeredRoles(($this->makeUser)('super_admin')))->toBe(['admin', 'staff', 'super_admin']);
});

it('offers nothing to an actor who may create accounts but not assign roles', function () {
    $permissions = ['access_admin_panel', 'view_any_user', 'create_user', 'reset_user_password'];

    // The pair: the same actor plus assign_role is offered the assignable roles,
    // so the picker follows the ability and not the role the actor happens to hold.
    expect(offeredRoles(($this->userWith)(...$permissions)))->toBe([])
        ->and(offeredRoles(($this->userWith)(...[...$permissions, 'assign_role'])))->toBe(['admin', 'staff']);
});

it('keeps a role the edited account already holds in the picker so an unrelated save still validates', function () {
    // A portal account is a user row holding `student`, and the Users list shows
    // it. The picker never OFFERS student, but hiding a held role would leave the
    // form's own state outside its options and reject every edit of that account.
    $admin = ($this->makeUser)('admin');
    $portalAccount = ($this->makeUser)('student', ['name' => 'Portal Person']);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $portalAccount->getKey()])
        ->fillForm(['name' => 'Renamed Portal Person'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($portalAccount->fresh()->name)->toBe('Renamed Portal Person')
        ->and($portalAccount->fresh()->roles()->pluck('name')->all())->toBe(['student']);
});

it('refuses on the server to grant student, to an admin and to a super admin alike', function (string $actorRole) {
    // The picker is not the control: a crafted payload can submit a role it never
    // rendered. Portal accounts come from IssuePortalCredentialAction, so neither
    // rank may attach the role through the staff screens.
    $actor = ($this->makeUser)($actorRole);
    $target = ($this->makeUser)('staff');

    expect(fn () => app(SyncUserRolesAction::class)->execute($actor, $target, ['staff', 'student']))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->roles()->pluck('name')->all())->toBe(['staff']);
})->with(['admin', 'super_admin']);

it('refuses on the server to strip student from a portal account', function () {
    $admin = ($this->makeUser)('admin');
    $portalAccount = ($this->makeUser)('student');

    expect(fn () => app(SyncUserRolesAction::class)->execute($admin, $portalAccount, ['staff']))
        ->toThrow(AuthorizationException::class);

    expect($portalAccount->fresh()->roles()->pluck('name')->all())->toBe(['student']);
});

it('answers the assignRole ability the picker asks with the same refusal for student', function () {
    $studentRole = Role::query()->where('name', 'student')->firstOrFail();
    $staffRole = Role::query()->where('name', 'staff')->firstOrFail();
    $admin = ($this->makeUser)('admin');

    expect(Gate::forUser($admin)->allows('assignRole', [User::class, $studentRole]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('assignRole', [User::class, $staffRole]))->toBeTrue();
});

it('does not offer a user who already has a staff profile when creating one', function () {
    $actor = ($this->makeUser)('admin');
    $withProfile = User::factory()->create(['name' => 'Aaa Has Profile']);
    StaffProfile::factory()->for($withProfile)->create();
    $free = User::factory()->create(['name' => 'Bbb Has None']);

    $offered = [];
    $searched = [];

    Livewire::actingAs($actor)
        ->test(CreateStaffProfile::class)
        ->assertFormFieldExists('user_id', function (Select $field) use (&$offered, &$searched): bool {
            $offered = array_keys($field->getOptions());
            $searched = array_keys($field->getSearchResults('Has'));

            return true;
        });

    // Both the initial list and the typed search leave out the taken account.
    expect($offered)->toContain($free->getKey())->not->toContain($withProfile->getKey())
        ->and($searched)->toBe([$free->getKey()]);
});

it('still shows the account of the profile being edited', function () {
    $actor = ($this->makeUser)('admin');
    $owner = User::factory()->create(['name' => 'Ccc Profile Owner']);
    $profile = StaffProfile::factory()->for($owner)->create();

    Livewire::actingAs($actor)
        ->test(EditStaffProfile::class, ['record' => $profile->getKey()])
        ->assertFormSet(['user_id' => $owner->getKey()])
        ->assertSee('Ccc Profile Owner');
});
