<?php

declare(strict_types=1);

use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Activitylog\Models\Activity;

/**
 * Changing one's own password on purpose (P4-T43).
 *
 * The page was built for the forced change (must_change_password); the owner's
 * decision of 2026-11-02 makes it the only way to change one's own password, from
 * the user menu and from one's own row in Users & roles. These tests prove the
 * voluntary case: the menu item, the current-password requirement, the session
 * that stays signed in, and the back link a flagged account is never offered. The
 * forced flow's own tests are in ForcePasswordChangeTest.
 *
 * The expected wording is written out here rather than read from lang/.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->makeUser = function (string $role, array $attributes = []): User {
        $user = User::factory()->create([...[
            'is_active' => true,
            'must_change_password' => false,
            'password' => Hash::make('existing-password-1'),
        ], ...$attributes]);
        $user->assignRole($role);

        return $user;
    };
});

/*
|--------------------------------------------------------------------------
| The user menu
|--------------------------------------------------------------------------
*/

it('puts Change password in the user menu of an admin and of a staff user', function (string $role) {
    $user = ($this->makeUser)($role);

    $html = (string) $this->actingAs($user)->get('/admin')->assertSuccessful()->getContent();

    expect($html)->toContain('Change password')
        ->and($html)->toContain('href="'.url('/admin/password-change').'"');
})->with(['admin' => 'admin', 'staff' => 'staff']);

it('puts Change password in the user menu in Arabic for an Arabic user', function () {
    $user = ($this->makeUser)('staff', ['locale' => 'ar']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('تغيير كلمة المرور')
        ->assertDontSee('Change password');
});

it('does not show a visitor who is not signed in the menu item', function () {
    $this->get('/admin')->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| The page, voluntarily
|--------------------------------------------------------------------------
*/

it('opens the page for an unflagged account, with a way back to the dashboard', function () {
    $user = ($this->makeUser)('admin');

    $this->actingAs($user)
        ->get('/admin/password-change')
        ->assertSuccessful()
        ->assertSee('Change your password')
        ->assertSee('Back to the dashboard')
        ->assertSee('href="'.url('/admin').'"', false);
});

it('offers a flagged account no link back, only the way out', function () {
    $user = ($this->makeUser)('admin', ['must_change_password' => true]);

    $this->actingAs($user)
        ->get('/admin/password-change')
        ->assertSuccessful()
        ->assertDontSee('Back to the dashboard')
        ->assertSee('action="'.route('filament.admin.auth.logout').'"', false);
});

it('changes the password when the current one is given, and lands on the dashboard with a notification', function () {
    $user = ($this->makeUser)('admin');

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'Brand-new-secret!',
            'password_confirmation' => 'Brand-new-secret!',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Password updated')
        ->assertRedirect(Filament::getPanel('admin')->getUrl());

    $user->refresh();

    expect(Hash::check('Brand-new-secret!', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and(Activity::query()->where('event', 'password_changed')->where('causer_id', $user->getKey())->count())->toBe(1);
});

it('rejects a wrong current password and changes nothing', function () {
    $user = ($this->makeUser)('staff');
    $original = $user->password;

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'not-my-password',
            'password' => 'Brand-new-secret!',
            'password_confirmation' => 'Brand-new-secret!',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);

    expect($user->fresh()->password)->toBe($original)
        ->and(Activity::query()->where('event', 'password_changed')->exists())->toBeFalse();
});

it('requires the current password to be present, not just correct', function () {
    $user = ($this->makeUser)('staff');

    Livewire::actingAs($user)
        ->test(PasswordChange::class)
        ->fillForm([
            'current_password' => '',
            'password' => 'Brand-new-secret!',
            'password_confirmation' => 'Brand-new-secret!',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password' => 'required']);

    expect(Hash::check('existing-password-1', $user->fresh()->password))->toBeTrue();
});

it('keeps the session signed in after a voluntary change: the next request is still authenticated', function () {
    $user = ($this->makeUser)('admin');
    $this->actingAs($user);

    // The snapshot a browser holds after loading the page, replayed with the form
    // filled in. A direct Livewire test would not run AuthenticateSession, which
    // is the middleware that signed the 2026-11-01 administrator out.
    $html = (string) $this->get('/admin/password-change')->assertSuccessful()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    $snapshot = null;

    foreach ($matches[1] as $raw) {
        $decoded = html_entity_decode($raw, ENT_QUOTES);

        if ((json_decode($decoded, true)['memo']['name'] ?? null) === PasswordChange::class) {
            $snapshot = $decoded;
        }
    }

    expect($snapshot)->not->toBeNull('The password change page rendered no component snapshot.');

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [
                'data.current_password' => 'existing-password-1',
                'data.password' => 'Brand-new-secret!',
                'data.password_confirmation' => 'Brand-new-secret!',
            ],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])
        ->assertSuccessful();

    expect(Hash::check('Brand-new-secret!', $user->fresh()->password))->toBeTrue();

    // Without the refreshed session hash this is a redirect to /admin/login.
    $this->get('/admin')->assertSuccessful();
});
