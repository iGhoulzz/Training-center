<?php

declare(strict_types=1);

use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('redirects a flagged user to the password change page', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
        // Known, because the form requires the current password since P1-T15.
        'password' => Hash::make('existing-password-1'),
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/admin/password-change');
});

it('does not redirect an unflagged user', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => false,
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('does not redirect on the password change page itself', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
        // Known, because the form requires the current password since P1-T15.
        'password' => Hash::make('existing-password-1'),
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin/password-change')
        ->assertSuccessful();
});

/*
 * The three cases above only prove the redirect lands somewhere reachable.
 * This proves the flagged user can actually get back out again: the Livewire
 * save must clear the flag, or the redirect above becomes a permanent trap.
 */
it('clears the flag once a new password is set', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
        // Known, because the form requires the current password since P1-T15.
        'password' => Hash::make('existing-password-1'),
    ]);
    $user->assignRole('admin');

    $this->actingAs($user);

    Livewire::test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('rejects a password shorter than twelve characters', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'must_change_password' => true,
        // Known, because the form requires the current password since P1-T15.
        'password' => Hash::make('existing-password-1'),
    ]);
    $user->assignRole('admin');

    $this->actingAs($user);

    Livewire::test(PasswordChange::class)
        ->fillForm([
            'current_password' => 'existing-password-1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});
