<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction;
use App\Domain\Enrollment\Actions\RevokeStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Certificate authorization, exercised through the real Actions (P3-T05)
|--------------------------------------------------------------------------
|
| CertificatePolicyTest (T4) already proves StudentCertificatePolicy's own
| branching in isolation. This file proves the SAME claims one layer up: that
| IssueStudentCertificateAction, ReplaceStudentCertificateAction and
| RevokeStudentCertificateAction actually consult that policy (rather than,
| say, checking the wrong ability or none at all) — the seeded roles hide a
| gap like that, because every real holder of one certificate ability holds
| all three, which is exactly why every actor below is a BESPOKE role holding
| exactly one.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');

    $this->course = Course::factory()->create(['total_hours' => 40]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();
    $this->student = Student::factory()->create();
    $this->enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    /** A bespoke role holding exactly the named abilities, and an actor wearing it. */
    $this->actorWith = function (array $abilities): User {
        $role = Role::findOrCreate('cert-auth-probe-'.uniqid(), 'web');
        $role->syncPermissions($abilities);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user->refresh();
    };
});

/*
|--------------------------------------------------------------------------
| No certificate ability at all
|--------------------------------------------------------------------------
*/

it('denies issuance to an actor holding no certificate ability', function () {
    $actor = ($this->actorWith)(['view_any_student']);

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($actor, $this->enrollment))
        ->toThrow(AuthorizationException::class);
});

it('denies replacement to an actor holding no certificate ability', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);
    $actor = ($this->actorWith)(['view_any_student']);

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($actor, $this->enrollment))
        ->toThrow(AuthorizationException::class);
});

it('denies revocation to an actor holding no certificate ability', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);
    $actor = ($this->actorWith)(['view_any_student']);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($actor, $this->enrollment, 'reason'))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| One ability does not stand in for another, through the real Actions
|--------------------------------------------------------------------------
|
| CertificatePolicyTest already proves this at the policy layer alone. Here
| the SAME bespoke actor is driven through the real Action, so a gap between
| "the policy answers correctly" and "the Action actually asks the policy"
| would surface as a false grant below, not just at the Gate::can() layer.
*/

it('lets an actor holding only issue_student_certificate issue, and nothing else', function () {
    $issuer = ($this->actorWith)(['issue_student_certificate']);

    $certificate = app(IssueStudentCertificateAction::class)->execute($issuer, $this->enrollment);
    expect($certificate->status)->toBe(CertificateStatus::Valid);

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($issuer, $this->enrollment))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($issuer, $this->enrollment, 'reason'))
        ->toThrow(AuthorizationException::class);
});

it('lets an actor holding only replace_student_certificate replace, and nothing else', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $replacer = ($this->actorWith)(['replace_student_certificate']);

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($replacer, $this->enrollment))
        ->toThrow(AuthorizationException::class);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($replacer, $this->enrollment);
    expect($replacement->status)->toBe(CertificateStatus::Valid);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($replacer, $this->enrollment, 'reason'))
        ->toThrow(AuthorizationException::class);
});

it('lets an actor holding only revoke_student_certificate revoke, and nothing else', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $revoker = ($this->actorWith)(['revoke_student_certificate']);

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($revoker, $this->enrollment))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($revoker, $this->enrollment))
        ->toThrow(AuthorizationException::class);

    $revoked = app(RevokeStudentCertificateAction::class)->execute($revoker, $this->enrollment, 'reason');
    expect($revoked->status)->toBe(CertificateStatus::Revoked);
});

/*
|--------------------------------------------------------------------------
| Direct permissions, with no role at all
|--------------------------------------------------------------------------
|
| The bespoke-role tests above prove the Actions do not hardcode a SEEDED
| role name. They do not prove each asks $user->can() rather than, say,
| $user->roles->isNotEmpty(). These actors hold no role whatsoever —
| CompletionAuthorizationTest's identical section is the precedent.
*/

it('grants issuance to a roleless user holding issue_student_certificate directly', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('issue_student_certificate');

    expect($actor->refresh()->roles)->toBeEmpty();

    $certificate = app(IssueStudentCertificateAction::class)->execute($actor->refresh(), $this->enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Valid);
});

it('denies a roleless user holding an unrelated permission', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('view_any_student');

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($actor->refresh(), $this->enrollment))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| delete_student_certificate — created inside this test, granted, still refused
|--------------------------------------------------------------------------
|
| T1 deliberately never seeds this permission — RolePermissionSeeder's own
| docblock records why. Asserting a refusal against a permission that does
| not exist would prove only that the permission is absent, which is a
| WEAKER claim than "the policy refuses unconditionally". Minting it here and
| granting it to an actor is what makes the second claim, not the first, the
| one under test — the identical shape CertificatePolicyTest (T4) already
| uses, reproduced here because P3-T05's own "Done when" list names it
| explicitly rather than pointing at T4's file.
*/

it('creates delete_student_certificate, grants it, and the policy still refuses', function () {
    Permission::findOrCreate('delete_student_certificate', 'web');

    $actor = ($this->actorWith)(['delete_student_certificate']);

    expect($actor->can('delete_student_certificate'))->toBeTrue('The probe permission was not actually granted.');

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect(Gate::forUser($actor)->allows('delete', $certificate))->toBeFalse()
        ->and($actor->can('delete', $certificate))->toBeFalse();
});

it('refuses delete_student_certificate even to a super admin', function () {
    // Rank does not open it either — CertificatePolicyTest proves this
    // against the class; this reproduces it against a REAL persisted row.
    Permission::findOrCreate('delete_student_certificate', 'web');

    $superAdmin = User::factory()->create(['is_active' => true]);
    $superAdmin->assignRole('super_admin');

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect($superAdmin->can('delete', $certificate))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No delete route exists anywhere on the register's Filament surface
|--------------------------------------------------------------------------
|
| Belt and braces with the policy proofs above: even if a permission existed
| and the policy somehow allowed it, there is no CREATE, EDIT or DELETE route
| registered for this resource to reach — docs/ENGINEERING.md's point that
| only NOT REGISTERING a handler closes it, ->visible(false) does not.
*/

it('registers exactly the index and view pages, and refuses create', function () {
    expect(array_keys(StudentCertificateResource::getPages()))->toBe(['index', 'view'])
        ->and(StudentCertificateResource::canCreate())->toBeFalse();
});
