<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction;
use App\Domain\Enrollment\Actions\RevokeStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\CertificateChangedException;
use App\Domain\Enrollment\Exceptions\NoValidCertificateException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| RevokeStudentCertificateAction (P3-T05)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    /*
     * THE CLOCK IS FROZEN, AND THAT IS A BUG FIX RATHER THAN TIDINESS.
     *
     * `completed_on` is written through CentreCalendar::localise() — Africa/Tripoli,
     * UTC+2 — while the app's own timezone is UTC, and CertificateReference::mint()
     * takes its year from CentreCalendar::yearOf() while these tests build expected
     * references from now()->year. Left on the wall clock, the date assertion below
     * was wrong every day between 22:00 and 24:00 UTC, and the retry tests' seeded
     * reference stopped colliding for the two hours before the Tripoli new year.
     *
     * 09:00 UTC is 11:00 in Tripoli: same calendar date, same year, no boundary
     * anywhere near it. Laravel's TestCase::tearDown() clears this between tests.
     */
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'UTC'));

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');

    $this->course = Course::factory()->create(['total_hours' => 40]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();
    $this->student = Student::factory()->create();
    $this->enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();
});

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('revokes the valid certificate with a reason, recording the actor and time', function () {
    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $revoked = app(RevokeStudentCertificateAction::class)->execute(
        $this->admin,
        $this->enrollment,
        'Issued against a miscounted attendance record.',
    );

    expect($revoked->getKey())->toBe($certificate->getKey())
        ->and($revoked->status)->toBe(CertificateStatus::Revoked)
        ->and($revoked->revoked_by)->toBe($this->admin->getKey())
        ->and($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->revocation_reason)->toBe('Issued against a miscounted attendance record.');

    // No valid certificate remains for the enrolment.
    expect(StudentCertificate::query()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('status', CertificateStatus::Valid)
        ->exists())->toBeFalse();

    // The row survives — revoking is not deleting.
    expect(StudentCertificate::query()->whereKey($certificate->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The reason is mandatory
|--------------------------------------------------------------------------
*/

it('refuses a blank reason', function (string $blank) {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($this->admin, $this->enrollment, $blank))
        ->toThrow(InvalidArgumentException::class);

    // Nothing moved — the row this fixture issued is still valid.
    expect(StudentCertificate::query()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('status', CertificateStatus::Valid)
        ->exists())->toBeTrue();
})->with([
    'empty string' => [''],
    'spaces only' => ['   '],
    'tab only' => ["\t"],
    'newline only' => ["\n"],
]);

it('accepts a reason that is not blank after trimming', function () {
    // The positive control for the dataset above: proves the guard reads
    // trim($reason), not merely "$reason is falsy" or "$reason has any
    // characters at all" — a reason of literally just whitespace differs from
    // one with real content by exactly the property this test isolates.
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $revoked = app(RevokeStudentCertificateAction::class)->execute(
        $this->admin,
        $this->enrollment,
        '  misconduct  ',
    );

    expect($revoked->status)->toBe(CertificateStatus::Revoked)
        // Stored verbatim, not trimmed — see the Action's own docblock.
        ->and($revoked->revocation_reason)->toBe('  misconduct  ');
});

/*
|--------------------------------------------------------------------------
| Refusals — no valid certificate to act on
|--------------------------------------------------------------------------
*/

it('refuses to revoke when no certificate was ever issued', function () {
    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($this->admin, $this->enrollment, 'reason'))
        ->toThrow(NoValidCertificateException::class);
});

it('refuses to revoke an already-revoked certificate', function () {
    StudentCertificate::factory()->for($this->enrollment)->revoked()->create();

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($this->admin, $this->enrollment, 'again'))
        ->toThrow(NoValidCertificateException::class);
});

it('refuses to revoke an already-replaced certificate', function () {
    StudentCertificate::factory()->for($this->enrollment)->replaced()->create();

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($this->admin, $this->enrollment, 'reason'))
        ->toThrow(NoValidCertificateException::class);
});

it('revokes successfully when a valid certificate genuinely stands', function () {
    // The positive control for the three refusals above.
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $revoked = app(RevokeStudentCertificateAction::class)->execute($this->admin, $this->enrollment, 'reason');

    expect($revoked->status)->toBe(CertificateStatus::Revoked);
});

/*
|--------------------------------------------------------------------------
| Authorization — bespoke single-ability roles
|--------------------------------------------------------------------------
*/

it('denies an actor who does not hold revoke_student_certificate', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $role = Role::findOrCreate('certificate-issuer-only-3', 'web');
    $role->syncPermissions(['issue_student_certificate']);

    $stranger = User::factory()->create(['is_active' => true]);
    $stranger->assignRole($role);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute($stranger->refresh(), $this->enrollment, 'reason'))
        ->toThrow(AuthorizationException::class);

    expect(StudentCertificate::query()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('status', CertificateStatus::Valid)
        ->exists())->toBeTrue();
});

it('grants revocation to a bespoke role holding only revoke_student_certificate', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $role = Role::findOrCreate('certificate-revoker-only', 'web');
    $role->syncPermissions(['revoke_student_certificate']);

    $revoker = User::factory()->create(['is_active' => true]);
    $revoker->assignRole($role);

    $revoked = app(RevokeStudentCertificateAction::class)->execute($revoker->refresh(), $this->enrollment, 'reason');

    expect($revoked->status)->toBe(CertificateStatus::Revoked);
});

/*
|--------------------------------------------------------------------------
| The activity trail
|--------------------------------------------------------------------------
|
| REVIEW FINDING, AND IT WAS A REAL HOLE. Design §6.4 says every issuance,
| replacement and revocation is activity-logged, and CLAUDE.md makes the log
| append-only — it is the audit evidence for a register whose entire purpose is
| recording who decided what. None of T5's five test files contained the string
| `activity`, and nulling the causer in all three Actions left the suite fully
| green. Nineteen other test files in this repository assert `causer_id`,
| including every comparable Action, so this was a gap in T5 rather than a
| house-wide omission.
|
| The assertions below name the actor VARIABLE, never a value re-read from the
| entry itself, so they cannot agree with themselves.
*/

it('logs the revocation against the actor, with the status transition in the diff', function () {
    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    // The fixture's enrolment, NOT a new one: `enrollments` is UNIQUE on
    // (student_id, batch_id) and beforeEach already created the pair's row.
    $certificate = app(IssueStudentCertificateAction::class)->execute($actor, $this->enrollment);

    app(RevokeStudentCertificateAction::class)->execute($actor, $this->enrollment, 'Issued against the wrong student.');

    $activity = Activity::query()
        ->where('subject_type', StudentCertificate::class)
        ->where('subject_id', $certificate->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull('The revocation wrote no activity entry at all.')
        ->and($activity->causer_id === null ? null : (int) $activity->causer_id)
        ->toBe($actor->getKey(), 'The revocation was logged with no causer, or with the wrong one.');

    $changes = $activity->attribute_changes;

    expect($changes->get('attributes')['status'] ?? null)->toBe('revoked')
        ->and($changes->get('old')['status'] ?? null)->toBe('valid')
        ->and($changes->get('attributes')['revoked_by'] ?? null)->toBe($actor->getKey());
});

it('refuses to revoke when the row the actor selected is no longer the current one', function () {
    /*
     * The revoke half of the same race — and the more damaging half. A
     * revocation carries a REASON, so acting on the wrong row attributes one
     * operator's stated justification to a certificate they never saw.
     */
    $original = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $successor = app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect(fn () => app(RevokeStudentCertificateAction::class)->execute(
        $this->admin,
        $this->enrollment,
        'Issued against the wrong student.',
        (int) $original->getKey(),
    ))->toThrow(CertificateChangedException::class);

    expect($successor->fresh()->status)->toBe(
        CertificateStatus::Valid,
        'The successor was revoked under a reason written about a different certificate.',
    );
});

it('revokes normally when the selected row IS still the current one', function () {
    // The positive control for the test above.
    $original = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $revoked = app(RevokeStudentCertificateAction::class)->execute(
        $this->admin,
        $this->enrollment,
        'Issued against the wrong student.',
        (int) $original->getKey(),
    );

    expect($revoked->status)->toBe(CertificateStatus::Revoked)
        ->and($revoked->getKey())->toBe($original->getKey());
});
