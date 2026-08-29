<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('allows an active staff user into the admin panel', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('staff');

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('denies a deactivated user', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('denies a student the admin panel', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('denies a user with no role', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The student panel (P3-T01)
|--------------------------------------------------------------------------
|
| canAccessPanel() previously ignored its $panel argument entirely and answered
| access_admin_panel for every panel, which would have admitted every staff
| account to /portal the moment the panel existed.
|
| It is now a match on the panel id with `default => false`. The unknown-panel
| case below is what makes that default load-bearing rather than decorative: a
| panel added later is refused until somebody decides otherwise, instead of
| inheriting whichever branch happened to be written last.
|
| The portal target is the password page. It is the only authenticated page the
| panel carries in this task — the four portal pages arrive in T7 — and it is
| enough, because whether it can be reached at all is decided by
| canAccessPanel().
*/

/** A student who can actually sign in: active, roled, and linked to a record. */
function portalStudent(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');
    Student::factory()->for($user)->create();

    return $user;
}

it('allows a linked active student into the portal', function () {
    $this->actingAs(portalStudent(), 'student')->get('/portal/password-change')->assertSuccessful();
});

it('does not let a web session reach the portal', function () {
    // The separate guard's whole purpose, asserted rather than assumed. This
    // account would pass canAccessPanel() on the portal — it is exactly the user
    // the test above admits — so a 200 here would mean the guard separation is
    // decorative and the panel is really gated by the `web` session.
    //
    // Filament's Authenticate middleware finds nobody on `student` and redirects
    // to the portal login. A redirect, not a 403: refusing an unauthenticated
    // visitor is a different answer from refusing an authenticated one.
    $this->actingAs(portalStudent())
        ->get('/portal/password-change')
        ->assertRedirect('/portal/login');
});

it('does not let a student session reach the admin panel', function () {
    // The same property in the other direction. An admin account authenticated
    // only on `student` is not authenticated on `web`, so /admin does not
    // recognise it — which is the class of bug the separate guard exists to
    // eliminate: reaching an admin view because a route was left unguarded.
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $this->actingAs($admin, 'student')
        ->get('/admin')
        ->assertRedirect('/admin/login');
});

it('denies a staff account the portal', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('staff');

    $this->actingAs($user, 'student')->get('/portal/password-change')->assertForbidden();
});

it('denies a deactivated student the portal', function () {
    $user = portalStudent();
    $user->update(['is_active' => false]);

    $this->actingAs($user, 'student')->get('/portal/password-change')->assertForbidden();
});

it('denies a student account with no linked student record', function () {
    // The account exists and holds the role, but there is no record behind it,
    // so there is nothing a portal page could show. Refused at the panel gate
    // rather than left for AuthenticatedStudent to throw on.
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');

    $this->actingAs($user, 'student')->get('/portal/password-change')->assertForbidden();
});

it('denies a student whose record was soft-deleted', function () {
    $user = portalStudent();
    Student::query()->where('user_id', $user->getKey())->first()?->delete();

    $this->actingAs($user, 'student')->get('/portal/password-change')->assertForbidden();
});

it('refuses an unknown panel id', function () {
    // The `default => false` arm, asserted directly. Without this the match is
    // two named branches and a fallthrough nobody has decided.
    $unknown = Panel::make()->id('reports')->path('reports');

    expect(portalStudent()->canAccessPanel($unknown))->toBeFalse();
});
