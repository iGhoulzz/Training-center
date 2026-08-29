<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Forced password change on the portal (P3-T01)
|--------------------------------------------------------------------------
|
| ForcePasswordChange and PasswordChange were both written for one panel and
| hardcoded it: PAGE_ROUTE and LOGOUT_ROUTE named filament.admin.*, the hold
| redirected to '/admin/password-change', and the success path ended in
| $this->redirect('/admin').
|
| Left alone, a student issued a temporary password would be redirected into a
| panel canAccessPanel() refuses them — and the guard's own exemption would never
| match the portal's password route, so the page meant to be the way out becomes
| unreachable. That is precisely the trap the admin panel's version was built to
| avoid, reintroduced one panel over.
|
| Both now resolve from Filament::getCurrentOrDefaultPanel(), through Filament's
| own route APIs rather than rebuilt name strings.
*/

/** A student issued a temporary password. */
function flaggedPortalStudent(): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
    ]);
    $user->assignRole('student');
    Student::factory()->for($user)->create();

    return $user;
}

it('holds a flagged student on the portal password page, not the admin one', function () {
    // Unfixed, PAGE_ROUTE is filament.admin.pages.password-change, so routeIs()
    // is false on the portal's own route and the guard redirects away from the
    // one page it is supposed to allow.
    $response = $this->actingAs(flaggedPortalStudent(), 'student')
        ->get('/portal/password-change');

    $response->assertSuccessful();

    expect($response->headers->get('Location'))->not->toBe(url('/admin/password-change'));
});

it('lets an unflagged student open the portal password page', function () {
    $user = flaggedPortalStudent();
    $user->update(['must_change_password' => false]);

    $this->actingAs($user, 'student')
        ->get('/portal/password-change')
        ->assertSuccessful();
});

it('returns a student to the portal after a successful change', function () {
    // Unfixed this lands on /admin — a panel this account cannot enter — so the
    // student completes the one thing they were held for and is bounced.
    $user = flaggedPortalStudent();

    $this->actingAs($user, 'student');
    Filament::setCurrentPanel('student');

    Livewire::test(PasswordChange::class)
        ->fillForm([
            // current_password is required and dehydrated: session access is not
            // credential ownership (P1-T15, finding 6). 'password' is what
            // UserFactory hashes.
            'current_password' => 'password',
            'password' => 'a-new-passphrase-9134',
            'password_confirmation' => 'a-new-passphrase-9134',
        ])
        ->call('save')
        ->assertRedirect('/portal');

    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('still returns a staff user to the admin panel after a successful change', function () {
    // The other half of the same claim: parameterising the redirect must not
    // move the panel that has worked since phase 1.
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
    ]);
    $user->assignRole('admin');

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');

    Livewire::test(PasswordChange::class)
        ->fillForm([
            // current_password is required and dehydrated: session access is not
            // credential ownership (P1-T15, finding 6). 'password' is what
            // UserFactory hashes.
            'current_password' => 'password',
            'password' => 'a-new-passphrase-9134',
            'password_confirmation' => 'a-new-passphrase-9134',
        ])
        ->call('save')
        ->assertRedirect('/admin');

    expect($user->fresh()->must_change_password)->toBeFalse();
});
