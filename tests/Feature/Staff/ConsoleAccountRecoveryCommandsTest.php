<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\ConsoleAccountRecovery;
use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/**
 * The server-console recovery commands (P4-T43): staff:issue-temporary-password and
 * staff:restore-user. They are trusted actorless paths, so the properties that
 * matter are what they WRITE (the audit entry, with no causer), what they REFUSE
 * (an unknown email, a deleted or not-deleted account) and that the password they
 * print is the one that works.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->makeUser = function (string $role, array $attributes = []): User {
        $user = User::factory()->create([...[
            'is_active' => true,
            'must_change_password' => false,
            'password' => Hash::make('old-password-1'),
        ], ...$attributes]);
        $user->assignRole($role);

        return $user;
    };

    /** The temporary password a --force run printed: the last non-empty line of the output. */
    $this->issuedPassword = function (string $email): string {
        expect(Artisan::call('staff:issue-temporary-password', ['email' => $email, '--force' => true]))->toBe(0);

        $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output())), fn (string $line): bool => $line !== ''));

        return (string) end($lines);
    };
});

/*
|--------------------------------------------------------------------------
| staff:issue-temporary-password
|--------------------------------------------------------------------------
*/

it('prints a password that works, forces a change at the next sign-in and ends the old password', function () {
    $user = ($this->makeUser)('admin', ['email' => 'locked.out@example.test']);

    $plain = ($this->issuedPassword)('locked.out@example.test');

    expect($plain)->toHaveLength(16)
        ->and(Hash::check($plain, $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('old-password-1', $user->fresh()->password))->toBeFalse()
        ->and($user->fresh()->must_change_password)->toBeTrue()
        ->and(Auth::guard('web')->attempt(['email' => 'locked.out@example.test', 'password' => $plain]))->toBeTrue();

    // And the account is held on the password page until it picks its own.
    Auth::guard('web')->logout();

    $this->actingAs($user->fresh())->get('/admin')->assertRedirect(url('/admin/password-change'));
});

it('records the issue in the activity log with no causer, marked as the server console, and without the secret', function () {
    $user = ($this->makeUser)('staff', ['email' => 'audited@example.test']);

    // A signed-in person in the shell's process must not be named for this.
    $bystander = ($this->makeUser)('admin');
    $this->actingAs($bystander);

    $before = (int) Activity::query()->max('id');

    $plain = ($this->issuedPassword)('audited@example.test');

    // The only entry the run wrote: the model's own `updated` entry is not added
    // on top, which would name the bystander as its causer.
    $entries = Activity::query()->where('id', '>', $before)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe(ActivityEvent::PASSWORD_RESET);

    $entry = $entries->first();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->subject_id)->toBe($user->getKey())
        ->and($entry->subject_type)->toBe($user->getMorphClass())
        ->and($entry->properties->get('source'))->toBe('console')
        ->and(json_encode($entry->properties))->not->toContain($plain)
        ->and(json_encode($entry->properties))->not->toContain($user->fresh()->password);
});

it('works in production, where the bootstrap command is the only other console writer', function () {
    app()->instance('env', 'production');
    ($this->makeUser)('admin', ['email' => 'prod@example.test']);

    expect(($this->issuedPassword)('prod@example.test'))->toHaveLength(16);
});

it('asks for confirmation, and a declined confirmation changes nothing', function () {
    $user = ($this->makeUser)('admin', ['email' => 'asked@example.test']);
    $original = $user->password;

    $this->artisan('staff:issue-temporary-password', ['email' => 'asked@example.test'])
        ->expectsConfirmation('Replace the password of asked@example.test with a temporary one? Their open sessions end and the change is recorded in the activity log.', 'no')
        ->expectsOutputToContain('Cancelled; nothing was changed.')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($original)
        ->and($user->fresh()->must_change_password)->toBeFalse()
        ->and(Activity::query()->where('event', ActivityEvent::PASSWORD_RESET)->exists())->toBeFalse();
});

it('issues the password once the confirmation is accepted', function () {
    $user = ($this->makeUser)('admin', ['email' => 'accepted@example.test']);

    $this->artisan('staff:issue-temporary-password', ['email' => 'accepted@example.test'])
        ->expectsConfirmation('Replace the password of accepted@example.test with a temporary one? Their open sessions end and the change is recorded in the activity log.', 'yes')
        ->assertSuccessful();

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(Activity::query()->where('event', ActivityEvent::PASSWORD_RESET)->count())->toBe(1);
});

it('refuses an email that no account has', function () {
    $this->artisan('staff:issue-temporary-password', ['email' => 'nobody@example.test', '--force' => true])
        ->expectsOutputToContain('No account has that email address.')
        ->assertFailed();

    expect(Activity::query()->where('event', ActivityEvent::PASSWORD_RESET)->exists())->toBeFalse();
});

it('refuses a deleted account and says to restore it first', function () {
    $user = ($this->makeUser)('admin', ['email' => 'gone@example.test']);
    $original = $user->password;
    $user->delete();

    $this->artisan('staff:issue-temporary-password', ['email' => 'gone@example.test', '--force' => true])
        ->expectsOutputToContain('Run staff:restore-user first.')
        ->assertFailed();

    expect(User::withTrashed()->find($user->getKey())->password)->toBe($original)
        ->and(Activity::query()->where('event', ActivityEvent::PASSWORD_RESET)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| staff:restore-user
|--------------------------------------------------------------------------
*/

it('restores a deleted account with the roles it held and can then sign in', function () {
    $user = ($this->makeUser)('admin', ['email' => 'back@example.test']);
    $user->delete();

    expect(User::query()->where('email', 'back@example.test')->exists())->toBeFalse();

    $this->artisan('staff:restore-user', ['email' => 'back@example.test'])
        ->expectsOutputToContain('Restored back@example.test')
        ->assertSuccessful();

    $restored = User::query()->where('email', 'back@example.test')->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->roles()->pluck('name')->all())->toBe(['admin'])
        ->and(Auth::guard('web')->attempt(['email' => 'back@example.test', 'password' => 'old-password-1']))->toBeTrue();
});

it('records the restore in the activity log, once, with no causer and marked as the server console', function () {
    $user = ($this->makeUser)('staff', ['email' => 'audited.back@example.test']);
    $user->delete();

    $bystander = ($this->makeUser)('admin');
    $this->actingAs($bystander);

    $before = (int) Activity::query()->max('id');

    $this->artisan('staff:restore-user', ['email' => 'audited.back@example.test'])->assertSuccessful();

    // The only entry the run wrote, not the model's own `restored` one as well.
    $entries = Activity::query()->where('id', '>', $before)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe(ActivityEvent::RESTORED);

    $entry = $entries->first();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->subject_id)->toBe($user->getKey())
        ->and($entry->properties->get('source'))->toBe('console');
});

it('refuses an account that is not deleted and writes nothing', function () {
    ($this->makeUser)('admin', ['email' => 'present@example.test']);

    $this->artisan('staff:restore-user', ['email' => 'present@example.test'])
        ->expectsOutputToContain('That account is not deleted; nothing was changed.')
        ->assertFailed();

    expect(Activity::query()->where('event', ActivityEvent::RESTORED)->exists())->toBeFalse();
});

it('refuses restoring an email that no account has', function () {
    $this->artisan('staff:restore-user', ['email' => 'nobody@example.test'])
        ->expectsOutputToContain('No account has that email address.')
        ->assertFailed();

    expect(Activity::query()->where('event', ActivityEvent::RESTORED)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The service, decided under its own lock
|--------------------------------------------------------------------------
*/

it('decides on the locked row, not on what the command read: no password for a deleted account, no restore for a live one', function () {
    $service = app(ConsoleAccountRecovery::class);

    $deleted = ($this->makeUser)('admin');
    $original = $deleted->password;
    $deleted->delete();

    $live = ($this->makeUser)('admin');

    expect($service->issueTemporaryPassword($deleted))->toBeNull()
        ->and(User::withTrashed()->find($deleted->getKey())->password)->toBe($original)
        ->and($service->restore($live))->toBeFalse()
        ->and(Activity::query()->whereIn('event', [ActivityEvent::PASSWORD_RESET, ActivityEvent::RESTORED])->exists())->toBeFalse();
});
