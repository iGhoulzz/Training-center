<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Actions\UploadStaffCertificateAction;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Private file delivery (P1-T15, group 2 finding 1)
|--------------------------------------------------------------------------
|
| Two routes serve bytes from a disk with no URL: scanned identity documents and
| staff photos. Each must prove FIVE independent outcomes, and each refusal must
| prove the bytes did not travel — not merely that the status was unfriendly.
|
| ASSERTING THE STATUS ALONE IS NOT ENOUGH. A 403 with the file in the body is
| still a leak, and a route that answers 500 while streaming would satisfy any
| "not 200" check. Every refusal below asserts the canary is absent from the
| response body.
|
| THE GAP THAT PROMPTED THIS. Both routes carried `web` and `throttle` only, and
| Laravel's stock `web` group has no AuthenticateSession — it is opt-in. So an
| administrator resetting a compromised account's password, which is the
| containment step this application supports, did not reach these routes. The
| session kept working.
|
| WHAT WAS ALREADY CORRECT, AND IS NOT DUPLICATED. Both controllers check
| is_active before the policy and before touching the disk, and both answer a
| guest with 403 rather than a redirect. The middleware adds session integrity
| and nothing else; these tests pin the existing behaviour so the fix cannot
| quietly change it.
*/

const FILE_CANARY = 'CANARY-PRIVATE-BYTES-DO-NOT-LEAK';

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    Storage::fake('private');

    $this->makeUser = function (?string $role, array $attributes = []): User {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('existing-password-1'),
            ...$attributes,
        ]);

        if ($role !== null) {
            $this->system->assignRoles($user, $role);
        }

        return $user->refresh();
    };

    /*
     * The paths match the exact shape each controller accepts, and that is not
     * incidental. StaffProfilePhotoController requires
     * `{DIRECTORY}/{ULID}.{jpg|png|webp}` — a deliberate guard so an imported or
     * hand-edited row cannot point an inline-rendered endpoint at HTML on the
     * application's own origin. A fixture with a friendly filename is refused by
     * that rule, correctly, and would leave these tests measuring a 404 that has
     * nothing to do with the guard under test.
     */
    $photoPath = UpdateStaffPhotoAction::DIRECTORY.'/'.Str::ulid().'.png';
    $certificatePath = UploadStaffCertificateAction::DIRECTORY.'/'.Str::ulid().'.pdf';

    $owner = ($this->makeUser)(null);
    $this->profile = StaffProfile::factory()->for($owner)->create([
        'profile_photo_path' => $photoPath,
    ]);

    $this->certificate = StaffCertificate::factory()->for($this->profile)->create([
        'disk' => 'private',
        'path' => $certificatePath,
    ]);

    Storage::disk('private')->put($certificatePath, FILE_CANARY);
    Storage::disk('private')->put($photoPath, FILE_CANARY);

    // The two routes, exercised identically. Every outcome below runs against
    // BOTH — a guarantee that holds for one and not the other is not a
    // guarantee, and grouping them in the route file is only half of that.
    $this->routes = [
        'certificate' => fn (): string => route('staff.certificates.download', ['certificate' => $this->certificate]),
        'photo' => fn (): string => route('staff.profiles.photo', ['profile' => $this->profile]),
    ];
});

/** Every refusal proves the bytes stayed on disk. */
function assertNoCanary(TestResponse $response): void
{
    expect($response->getContent())->not->toContain(
        FILE_CANARY,
        'The response carried the private file contents despite refusing the request.',
    );
}

/*
|--------------------------------------------------------------------------
| 1. Authorized, active user receives the intended bytes
|--------------------------------------------------------------------------
*/

it('serves the bytes to an authorized active user', function (string $route) {
    $actor = ($this->makeUser)('admin');

    $response = $this->actingAs($actor)->get(($this->routes[$route])());

    $response->assertSuccessful();

    // The control for every other case in this file. Without it, a refusal
    // assertion cannot distinguish a working guard from a broken route.
    expect($response->streamedContent())->toContain(FILE_CANARY);
})->with(['certificate', 'photo']);

/*
|--------------------------------------------------------------------------
| 2. Guest is refused — as a 403, deliberately, not a redirect
|--------------------------------------------------------------------------
*/

it('refuses a guest with 403 and no bytes', function (string $route) {
    // 403 rather than a login redirect is the existing, intended behaviour: a
    // file endpoint should not tell an anonymous caller where to authenticate,
    // and a redirect would confirm the address exists. The session middleware
    // passes a guest straight through so the controller can answer.
    $response = $this->get(($this->routes[$route])());

    $response->assertForbidden();
    assertNoCanary($response);
})->with(['certificate', 'photo']);

/*
|--------------------------------------------------------------------------
| 3. Authenticated but without the file permission
|--------------------------------------------------------------------------
*/

it('refuses an authenticated user who lacks the file permission', function (string $route) {
    // A synthetic role with panel access and nothing else: authenticated, active,
    // and holding no grant over either file type.
    $role = Role::create(['name' => 'no_file_grants', 'guard_name' => 'web']);
    $role->givePermissionTo('access_admin_panel');

    $actor = ($this->makeUser)('no_file_grants');

    $response = $this->actingAs($actor)->get(($this->routes[$route])());

    $response->assertForbidden();
    assertNoCanary($response);
})->with(['certificate', 'photo']);

/*
|--------------------------------------------------------------------------
| 4. Deactivated user
|--------------------------------------------------------------------------
*/

it('refuses a deactivated user even with the permission', function (string $route) {
    // Enforced by the CONTROLLERS, before the policy and before the disk read —
    // verified in place rather than duplicated into the middleware. This test
    // exists so that behaviour cannot regress unnoticed.
    $actor = ($this->makeUser)('admin', ['is_active' => false]);

    $response = $this->actingAs($actor)->get(($this->routes[$route])());

    $response->assertForbidden();
    assertNoCanary($response);
})->with(['certificate', 'photo']);

/*
|--------------------------------------------------------------------------
| 5. A session opened before a password change
|--------------------------------------------------------------------------
*/

it('invalidates a session opened before the password hash changed', function (string $route) {
    $actor = ($this->makeUser)('admin');
    $url = ($this->routes[$route])();

    $this->actingAs($actor);

    // FIRST request establishes the session hash. AuthenticateSession stores
    // password_hash_web on the first pass and only compares on later ones, so
    // without this the change below has nothing to be measured against.
    $this->get($url)->assertSuccessful();

    // An administrator contains a compromise, or the owner locks out a thief.
    $actor->forceFill(['password' => Hash::make('reset-by-admin-2')])->save();

    // The SAME request, on the SAME session.
    $response = $this->get($url);

    // All three, because any one alone can pass for the wrong reason.
    $response->assertRedirect('/admin/login');
    assertNoCanary($response);
    expect(auth()->check())->toBeFalse('The session survived the password change.');
})->with(['certificate', 'photo']);

/*
|--------------------------------------------------------------------------
| The structural guarantee: the two routes cannot drift apart
|--------------------------------------------------------------------------
*/

it('applies the session guard to every private file route', function () {
    // Behavioural tests catch a route that loses the middleware today. This
    // catches the third private-file route somebody adds next year without it.
    foreach (['staff.certificates.download', 'staff.profiles.photo'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        // in_array, not expect()->toContain(): that helper is variadic, so the
        // failure message is read as a second expected middleware and the
        // assertion silently becomes stricter than intended.
        expect(in_array(AuthenticatePrivateFileSession::class, $middleware, true))->toBeTrue(
            "{$name} does not carry the private-file session guard.",
        );
    }
});

it('sends an invalidated session to the panel login without depending on panel state', function () {
    // Filament's AuthenticateSession resolves Filament::getLoginUrl() through
    // the panel currently serving the request. These are plain web routes, not
    // panel routes, so that lookup rests on ambient state they do not have — and
    // a failure to resolve it would turn a clean refusal into a 500.
    $middleware = new AuthenticatePrivateFileSession(app('auth'));

    $redirect = (new ReflectionMethod($middleware, 'redirectTo'))
        ->invoke($middleware, request());

    expect($redirect)->toBe('/admin/login');
});
