<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Authentication on `student`, authorization on `web`
|--------------------------------------------------------------------------
|
| THE MECHANISM, because it is silent when it breaks.
|
| Filament's Authenticate middleware calls
| $this->auth->shouldUse(Filament::getAuthGuard())
| (vendor/filament/filament/src/Http/Middleware/Authenticate.php:25).
|
| AuthManager::shouldUse() delegates to setDefaultDriver(), which WRITES
| $this->app['config']['auth.defaults.guard'] (AuthManager.php:206-224). It does
| not merely set a property on the manager.
|
| Spatie's Guard::getDefaultName() reads config('auth.defaults.guard') and
| returns it whenever it is among the guards whose provider matches the model.
| Both `web` and `student` use the `users` provider, so `student` qualifies.
|
| Without a pin, every permission lookup inside a portal request would therefore
| resolve against guard_name = 'student', for which no permission rows exist, and
| every check would return FALSE — silently, looking like a permissions bug
| rather than a guard bug.
|
| User::$guard_name = 'web' short-circuits Guard::getNames() before it consults
| config at all.
|
| These are REAL REQUESTS, not config reads. Reading config/auth.php back proves
| nothing about what Filament does to it mid-request, which is the entire defect.
*/

/** An active, roled student linked to a live record. */
function guardTestStudent(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('student');
    Student::factory()->for($user)->create();

    return $user;
}

it('resolves student permissions on the web guard during a portal request', function () {
    $user = guardTestStudent();

    // Reaching the page at all is decided by canAccessPanel(), which asks
    // $this->can('access_student_portal'). A 200 is therefore already proof the
    // lookup resolved somewhere that has rows.
    $this->actingAs($user, 'student')->get('/portal/password-change')->assertSuccessful();

    // And the default guard really was moved to `student` by the request, which
    // is the condition that would break an unpinned lookup. Asserted after the
    // request because the test app instance is not rebuilt between the two.
    expect(config('auth.defaults.guard'))->toBe('student');

    // With the guard still pointing at `student`, a granted ability must answer
    // true. Unpinned, Spatie would look for guard_name = 'student' here.
    expect($user->fresh()->can('view_own_student_record'))->toBeTrue()
        ->and($user->fresh()->can('view_own_enrollment'))->toBeTrue()
        ->and($user->fresh()->can('view_own_balance'))->toBeTrue()
        ->and($user->fresh()->can('view_own_certificate'))->toBeTrue();
});

it('still refuses an ability the student was never granted', function () {
    // The negative control. Pinning the guard must not turn every check true —
    // a pin that made can() answer true for everything would pass the test above
    // while destroying the portal's whole authorization story.
    $user = guardTestStudent();

    $this->actingAs($user, 'student')->get('/portal/password-change')->assertSuccessful();

    expect($user->fresh()->can('access_admin_panel'))->toBeFalse()
        ->and($user->fresh()->can('view_any_student'))->toBeFalse()
        ->and($user->fresh()->can('view_any_student_certificate'))->toBeFalse();
});

it('keeps admin permissions resolving after an admin request', function () {
    // The pin must not disturb the panel that has worked since phase 1.
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin')->assertSuccessful();

    expect($admin->fresh()->can('access_admin_panel'))->toBeTrue()
        ->and($admin->fresh()->can('view_any_student'))->toBeTrue();
});
