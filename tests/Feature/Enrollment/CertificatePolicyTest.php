<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Policies\StudentCertificatePolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    /**
     * An actor holding exactly one ability and nothing else.
     *
     * The SEEDED roles hide permission gaps: an admin holds so much that a
     * policy consulting the wrong ability still answers correctly for them. A
     * bespoke single-ability role is the only way to see which ability a branch
     * actually reads.
     */
    $this->actorWith = function (string ...$abilities): User {
        $role = Role::findOrCreate('probe-'.uniqid(), 'web');
        $role->syncPermissions($abilities);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user->refresh();
    };
});

/*
|--------------------------------------------------------------------------
| Policy resolution without an AppServiceProvider entry
|--------------------------------------------------------------------------
*/

it('resolves the policy by convention, with no Gate::policy registration', function () {
    /*
     * MEASURED, NOT ASSUMED.
     *
     * Gate::guessPolicyName() walks every namespace prefix longest-first
     * (Gate.php:721-724), which resolves
     * App\Domain\Enrollment\Models\StudentCertificate to
     * App\Domain\Enrollment\Policies\StudentCertificatePolicy unaided.
     *
     * Phase 2 established that nine of AppServiceProvider's eleven registrations
     * are redundant and two are not, and the difference was only ever settled by
     * measuring. So this asserts the resolution rather than trusting the pattern
     * — if it ever stops resolving, every check silently returns false.
     */
    expect(Gate::getPolicyFor(StudentCertificate::class))
        ->toBeInstanceOf(StudentCertificatePolicy::class);

    expect(file_get_contents(base_path('app/Providers/AppServiceProvider.php')))
        ->not->toContain('StudentCertificate');
});

/*
|--------------------------------------------------------------------------
| The five abilities, each read by the branch that claims it
|--------------------------------------------------------------------------
*/

it('grants each ability only to an actor holding that exact permission', function (string $method, string $ability) {
    $holder = ($this->actorWith)($ability);
    $stranger = ($this->actorWith)('view_any_student');

    expect($holder->can($method, StudentCertificate::class))->toBeTrue(
        "An actor holding {$ability} was refused {$method}."
    )->and($stranger->can($method, StudentCertificate::class))->toBeFalse(
        "An actor holding no certificate ability was allowed {$method}."
    );
})->with([
    'viewAny' => ['viewAny', 'view_any_student_certificate'],
    'view' => ['view', 'view_student_certificate'],
    'issue' => ['issue', 'issue_student_certificate'],
    'replace' => ['replace', 'replace_student_certificate'],
    'revoke' => ['revoke', 'revoke_student_certificate'],
]);

it('does not let one certificate ability stand in for another', function () {
    // The gap a seeded role hides: every real holder has all three acts, so a
    // policy reading issue_ for all of them would pass every role-based test.
    $issuer = ($this->actorWith)('issue_student_certificate');

    expect($issuer->can('issue', StudentCertificate::class))->toBeTrue()
        ->and($issuer->can('replace', StudentCertificate::class))->toBeFalse()
        ->and($issuer->can('revoke', StudentCertificate::class))->toBeFalse()
        ->and($issuer->can('viewAny', StudentCertificate::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| create, update and delete are refused unconditionally
|--------------------------------------------------------------------------
*/

it('refuses create, update and delete even to an actor granted the permission', function (string $method, string $ability) {
    /*
     * THE PERMISSION IS CREATED HERE, INSIDE THE TEST.
     *
     * T1 deliberately never seeds these — a certificate is issued, replaced or
     * revoked, never created, updated or deleted, and seeding an ability nothing
     * honours invites someone to wire it up later.
     *
     * That absence is exactly why this test has to mint the permission itself.
     * Asserting "the actor is refused" against a permission that does not exist
     * would pass for the wrong reason: it would prove the permission is missing,
     * not that the policy refuses. Creating it and granting it is the stronger
     * statement, and it is the same shape ActivityAppendOnlyTest uses for
     * delete_activity.
     */
    Permission::findOrCreate($ability, 'web');

    $actor = ($this->actorWith)($ability);

    expect($actor->can($ability))->toBeTrue('The probe permission was not actually granted.')
        ->and($actor->can($method, StudentCertificate::class))->toBeFalse(
            "The policy allowed {$method} to an actor holding {$ability}."
        );
})->with([
    'create' => ['create', 'create_student_certificate'],
    'update' => ['update', 'update_student_certificate'],
    'delete' => ['delete', 'delete_student_certificate'],
]);

it('refuses create, update and delete to a super admin', function (string $method) {
    // Rank does not open these. An issued row is immutable and undeletable for
    // everyone, which is what makes the register audit evidence rather than a
    // working document.
    $superAdmin = User::factory()->create(['is_active' => true]);
    $superAdmin->assignRole('super_admin');

    expect($superAdmin->can($method, StudentCertificate::class))->toBeFalse();
})->with(['create', 'update', 'delete']);

/*
|--------------------------------------------------------------------------
| The seeded roles, as design section 8.2 assigns them
|--------------------------------------------------------------------------
*/

it('lets staff read the register and change nothing in it', function () {
    $staff = User::factory()->create(['is_active' => true]);
    $staff->assignRole('staff');

    expect($staff->can('viewAny', StudentCertificate::class))->toBeTrue()
        ->and($staff->can('view', StudentCertificate::class))->toBeTrue()
        ->and($staff->can('issue', StudentCertificate::class))->toBeFalse()
        ->and($staff->can('replace', StudentCertificate::class))->toBeFalse()
        ->and($staff->can('revoke', StudentCertificate::class))->toBeFalse();
});

it('gives a student nothing on the register at all', function () {
    // A student's own certificate reaches them through view_own_certificate on
    // the portal (T7), never through this policy, which is register-wide.
    $student = User::factory()->create(['is_active' => true]);
    $student->assignRole('student');

    foreach (['viewAny', 'view', 'issue', 'replace', 'revoke', 'create', 'update', 'delete'] as $method) {
        expect($student->can($method, StudentCertificate::class))->toBeFalse(
            "A student was allowed {$method} on the certificate register."
        );
    }
});
