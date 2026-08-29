<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Logging out of one panel ends the other (P3-T01)
|--------------------------------------------------------------------------
|
| The two guards share Laravel's session cookie and hold separate authentication
| state inside it — login_student_… beside login_web_…. They are NOT separate
| browser sessions, and an earlier draft of the phase 3 design said they were.
|
| Filament\Auth\Http\Controllers\LogoutController calls
| Filament::auth()->logout() and then session()->invalidate(). The logout is
| per-guard; the invalidate is not — it flushes the whole session, taking the
| other guard's login with it.
|
| ACCEPTED, NOT WORKED AROUND. It errs toward logging out too much, and a
| per-guard logout would be custom auth code on the surface system design §4
| refused to write custom auth code for. These tests pin the behaviour so that a
| later reader does not "fix" it into something more permissive.
*/

function logoutTestStudent(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');
    Student::factory()->for($user)->create();

    return $user;
}

it('flushes the whole session when a student logs out of the portal', function () {
    // A canary in the shared store. Anything the other guard put there — its
    // login key included — goes the same way.
    $this->actingAs(logoutTestStudent(), 'student');

    session(['canary' => 'still-here']);
    session()->save();

    $this->post('/portal/logout');

    expect(session()->has('canary'))->toBeFalse(
        'The portal logout left session state standing, so it did not invalidate '
        .'the shared session and an /admin login in the same browser would survive.'
    );
});

it('leaves no web-guard login behind after a portal logout', function () {
    // The property stated in terms of the thing that matters, rather than a
    // canary: after the portal logout, the shared session holds no `web`
    // authentication state.
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    Auth::guard('web')->login($admin);
    Auth::guard('student')->login(logoutTestStudent());

    session()->save();

    expect(Auth::guard('web')->check())->toBeTrue();

    $this->post('/portal/logout');

    // _token and _flash survive, because invalidate() regenerates rather than
    // leaving the session unusable. What must NOT survive is any guard's login
    // key — asserting "empty" instead would fail on framework plumbing and say
    // nothing about the property.
    $remaining = array_filter(
        array_keys(session()->all()),
        fn (string $key): bool => str_starts_with($key, 'login_'),
    );

    expect($remaining)->toBeEmpty(
        'A login key survived the portal logout, so the other guard was not taken '
        .'down with it: '.implode(', ', $remaining)
    );
});

it('flushes the whole session when a staff user logs out of the admin panel', function () {
    // The same in the other direction, so the behaviour is pinned symmetrically
    // rather than looking like something peculiar to the new panel.
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('admin');

    $this->actingAs($user);

    session(['canary' => 'still-here']);
    session()->save();

    $this->post('/admin/logout');

    expect(session()->has('canary'))->toBeFalse();
});
