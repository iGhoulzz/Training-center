<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A clearer sign-in message when the password is right and the door is wrong
| (QA Q7 / P3-15, owner decision of 2026-10-17)
|--------------------------------------------------------------------------
|
| Filament answers "These credentials do not match our records" to a correct
| email and password that canAccessPanel() refuses, so someone using the right
| password at the wrong sign-in page is told the password is wrong. The new
| message is raised ONLY after the credentials have been validated; a wrong
| password, and an email nobody has, keep Filament's sentence word for word - the
| page must not become a way to learn which addresses have accounts.
|
| The expected sentences are written out here rather than read from the lang
| files, so a lang key quietly pointing at the wrong text fails these tests.
*/

const REFUSED_DOOR = "This account can't sign in here. Check that you are on the right sign-in page, or ask an administrator.";
const WRONG_CREDENTIALS = 'These credentials do not match our records.';

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    // The account's real password, so a "right password" case is genuinely right.
    $this->makeAccount = function (string $role, array $attributes = []): User {
        $user = User::factory()->create([
            ...['is_active' => true, 'password' => Hash::make('a-known-secret-1')],
            ...$attributes,
        ]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->makeStudentAccount = function (array $attributes = []): User {
        $user = ($this->makeAccount)('student', $attributes);
        Student::factory()->for($user)->create();

        return $user;
    };

    /**
     * Attempt a sign-in on a panel's own login page, and return the component.
     */
    $this->attempt = function (string $panel, string $email, string $password) {
        Filament::setCurrentPanel($panel);

        return Livewire::test(Login::class)
            ->fillForm(['email' => $email, 'password' => $password])
            ->call('authenticate');
    };
});

it('serves our login page on both panels', function (string $url) {
    $this->get($url)->assertSuccessful()->assertSeeLivewire(Login::class);
})->with(['/admin/login', '/portal/login']);

/*
|--------------------------------------------------------------------------
| Right password, wrong door
|--------------------------------------------------------------------------
*/

it('tells a student with the right password that the admin panel is not for them', function () {
    $student = ($this->makeStudentAccount)();

    $attempt = ($this->attempt)('admin', $student->email, 'a-known-secret-1');

    expect($attempt->errors()->get('data.email'))->toBe([REFUSED_DOOR]);
    $this->assertGuest('web');
    $this->assertGuest('student');
});

it('tells a staff member with the right password that the portal is not for them', function () {
    $staff = ($this->makeAccount)('staff');

    $attempt = ($this->attempt)('student', $staff->email, 'a-known-secret-1');

    expect($attempt->errors()->get('data.email'))->toBe([REFUSED_DOOR]);
    $this->assertGuest('web');
    $this->assertGuest('student');
});

it('tells a deactivated account with the right password that it cannot sign in', function (string $panel) {
    $admin = ($this->makeAccount)('admin', ['is_active' => false]);

    $attempt = ($this->attempt)($panel, $admin->email, 'a-known-secret-1');

    expect($attempt->errors()->get('data.email'))->toBe([REFUSED_DOOR]);
    $this->assertGuest('web');
    $this->assertGuest('student');
})->with(['admin', 'student']);

it('tells an active account that holds no access permission the same thing', function () {
    $roleless = User::factory()->create(['is_active' => true, 'password' => Hash::make('a-known-secret-1')]);

    $attempt = ($this->attempt)('admin', $roleless->email, 'a-known-secret-1');

    expect($attempt->errors()->get('data.email'))->toBe([REFUSED_DOOR]);
});

/*
|--------------------------------------------------------------------------
| Everything else keeps Filament's sentence, exactly
|--------------------------------------------------------------------------
*/

it('keeps the old message for a wrong password on an account that could sign in', function () {
    $admin = ($this->makeAccount)('admin');

    $attempt = ($this->attempt)('admin', $admin->email, 'not-the-password');

    expect($attempt->errors()->get('data.email'))->toBe([WRONG_CREDENTIALS]);
    $this->assertGuest('web');
});

it('keeps the old message for an email that has no account', function () {
    $attempt = ($this->attempt)('admin', 'nobody@example.test', 'whatever-it-is');

    expect($attempt->errors()->get('data.email'))->toBe([WRONG_CREDENTIALS]);
});

it('keeps the old message for a WRONG password on an account the panel would refuse', function (string $panel, string $accountKind) {
    // The enumeration check. If this said "can't sign in here", anyone could learn
    // that an address belongs to a student, or to a deactivated account, without
    // knowing a password.
    $account = match ($accountKind) {
        'student' => ($this->makeStudentAccount)(),
        'staff' => ($this->makeAccount)('staff'),
        'deactivated' => ($this->makeAccount)('admin', ['is_active' => false]),
    };

    $attempt = ($this->attempt)($panel, $account->email, 'not-the-password');

    expect($attempt->errors()->get('data.email'))->toBe([WRONG_CREDENTIALS]);
})->with([
    'student at admin' => ['admin', 'student'],
    'staff at portal' => ['student', 'staff'],
    'deactivated at admin' => ['admin', 'deactivated'],
]);

it('still signs in an account the panel accepts', function (string $panel, string $role, string $guard) {
    $account = $role === 'student' ? ($this->makeStudentAccount)() : ($this->makeAccount)($role);

    $attempt = ($this->attempt)($panel, $account->email, 'a-known-secret-1');

    expect($attempt->errors()->all())->toBe([]);
    $this->assertAuthenticatedAs($account, $guard);
})->with([
    'admin' => ['admin', 'admin', 'web'],
    'student' => ['student', 'student', 'student'],
]);

/*
|--------------------------------------------------------------------------
| What the failed attempt leaves behind, unchanged
|--------------------------------------------------------------------------
*/

it('still records the refused attempt as a failed login against the email, with no causer', function () {
    // The login_failed entry AppServiceProvider writes from the Failed event.
    // Filament fires that event for a right-password-wrong-door attempt exactly as
    // for a wrong password, and this page changes the message only.
    $student = ($this->makeStudentAccount)();

    ($this->attempt)('admin', $student->email, 'a-known-secret-1');

    $entry = Activity::query()->where('event', 'login_failed')->sole();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->getProperty('email'))->toBe($student->email)
        ->and(json_encode($entry->properties->all()))->not->toContain('a-known-secret-1');
});

it('still records a wrong password the same way', function () {
    $admin = ($this->makeAccount)('admin');

    ($this->attempt)('admin', $admin->email, 'not-the-password');

    $entry = Activity::query()->where('event', 'login_failed')->sole();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->getProperty('email'))->toBe($admin->email);
});

it('still rate limits the page to five attempts, refused ones included', function () {
    // The throttle is the parent's and runs before any credential is checked.
    // Five right-password-wrong-door attempts use it up; the sixth is throttled
    // without anyone being asked whether the password was right.
    $student = ($this->makeStudentAccount)();

    Filament::setCurrentPanel('admin');
    $page = Livewire::test(Login::class)
        ->fillForm(['email' => $student->email, 'password' => 'a-known-secret-1']);

    foreach (range(1, 5) as $ignored) {
        $page->call('authenticate');

        expect($page->errors()->get('data.email'))->toBe([REFUSED_DOOR]);
    }

    // Nothing has been announced so far: five refusals are validation errors.
    $page->assertNotNotified();

    $page->call('authenticate')->assertNotified('Too many login attempts');
});
