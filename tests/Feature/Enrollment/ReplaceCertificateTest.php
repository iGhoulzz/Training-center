<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException;
use App\Domain\Enrollment\Exceptions\NoValidCertificateException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ReplaceStudentCertificateAction (P3-T05)
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

    $this->course = Course::factory()->create(['name_en' => 'Original Course Name', 'total_hours' => 40]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();
    $this->student = Student::factory()->create(['first_name' => 'Amina', 'last_name' => 'Zarrouk']);
    $this->enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();
});

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('marks the old row replaced, writes a new valid row, and points the new row at the old one', function () {
    $original = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect($original->fresh()->status)->toBe(CertificateStatus::Replaced)
        ->and($replacement->status)->toBe(CertificateStatus::Valid)
        ->and($replacement->replaces_certificate_id)->toBe($original->getKey())
        ->and($replacement->reference_number)->not->toBe($original->reference_number)
        ->and($replacement->enrollment_id)->toBe($this->enrollment->getKey());

    // The pointer lives on the NEW row, not the old one (design section 6.4).
    expect($original->fresh()->replaces_certificate_id)->toBeNull();

    // Exactly one VALID row for the enrolment at any time.
    expect(StudentCertificate::query()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('status', CertificateStatus::Valid)
        ->count())->toBe(1);
});

it('re-derives the snapshot from the current student and course, not from the old certificate row', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    // A correction between issuance and replacement — precisely the scenario
    // a Replace exists to fix.
    $this->student->update(['first_name' => 'Amina', 'last_name' => 'Zarrouk-Corrected']);
    $this->course->update(['name_en' => 'Corrected Course Name']);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect($replacement->student_name)->toBe('Amina Zarrouk-Corrected')
        ->and($replacement->course_name)->toBe('Corrected Course Name');
});

/*
|--------------------------------------------------------------------------
| Refusals — no valid certificate to act on
|--------------------------------------------------------------------------
*/

it('refuses to replace when no certificate was ever issued', function () {
    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment))
        ->toThrow(NoValidCertificateException::class);
});

it('refuses to replace an already-revoked certificate', function () {
    StudentCertificate::factory()->for($this->enrollment)->revoked()->create();

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment))
        ->toThrow(NoValidCertificateException::class);
});

it('refuses to replace an already-replaced certificate', function () {
    StudentCertificate::factory()->for($this->enrollment)->replaced()->create();

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment))
        ->toThrow(NoValidCertificateException::class);
});

it('replaces successfully when a valid certificate genuinely stands', function () {
    // The positive control for the three refusals above: proves
    // NoValidCertificateException is reachable AND avoidable from the same
    // fixture shape, not merely unconditional.
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect($replacement->status)->toBe(CertificateStatus::Valid);
});

/*
|--------------------------------------------------------------------------
| The transaction — a forced failure on the insert leaves the original valid
|--------------------------------------------------------------------------
*/

it('rolls back the whole transaction when the new insert raises, leaving the original certificate valid', function () {
    /*
     * WHAT "FORCED FAILURE" ACTUALLY MEANS HERE, STATED PLAINLY.
     *
     * The throw comes from a DB::listen() callback, and QueryExecuted is a
     * POST-execution event — so the INSERT ran and then an exception was raised
     * on top of it. It is not a failure of the insert itself. Review flagged the
     * gap between the name and the mechanism; the name is narrowed rather than
     * the mechanism changed, because this shape actually exercises the rollback
     * MORE strongly: a row genuinely existed and had to be undone.
     *
     * Mutation-verified: replacing DB::transaction() in the Action with a bare
     * closure turns this red with the original row reading `replaced` instead of
     * `valid`.
     */
    $original = app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $dispatcher = clone DB::getEventDispatcher();
    DB::listen(function ($query): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'insert into `student_certificates`')) {
            throw new RuntimeException('forced replacement insert failure');
        }
    });

    try {
        expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment))
            ->toThrow(RuntimeException::class, 'forced replacement insert failure');
    } finally {
        DB::setEventDispatcher($dispatcher);
    }

    // THE PROOF: the old row is still valid, not stranded as replaced with no
    // successor. Reload from the database, not the in-memory $original — the
    // whole point is whether the COMMITTED state rolled back.
    expect($original->fresh()->status)->toBe(CertificateStatus::Valid);

    // And no second row exists at all — the insert never committed either.
    expect(StudentCertificate::query()->where('enrollment_id', $this->enrollment->getKey())->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Retry discrimination — ReplaceStudentCertificateAction's own copy
|--------------------------------------------------------------------------
|
| This Action carries its own independent copy of
| rethrowUnlessReferenceCollision() — not a shared base class with
| IssueStudentCertificateAction's — so a defect in this copy would not be
| caught by IssueCertificateTest proving the other one. "Test the surface,
| not the instance you just fixed" (docs/ENGINEERING.md): both copies get the
| identical proof.
*/

it('retries the whole transaction and succeeds when the drawn reference collides', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $year = now()->year;
    $collidingReference = 'TC-'.$year.'-33333333';

    $otherEnrollment = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->for($otherEnrollment)->create(['reference_number' => $collidingReference]);

    $calls = 0;
    $alphabetLastIndex = strlen(CertificateReference::ALPHABET) - 1;

    $reference = new CertificateReference(function (int $max) use (&$calls, $alphabetLastIndex): int {
        $calls++;

        return $calls <= 8 ? 1 : $alphabetLastIndex;
    });

    app()->instance(CertificateReference::class, $reference);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    expect($replacement->status)->toBe(CertificateStatus::Valid)
        ->and($replacement->reference_number)->not->toBe($collidingReference)
        ->and($calls)->toBe(16, 'Expected exactly two mint() attempts (8 draws each): one collision, one retry that succeeded.');
});

it('refuses via CertificateAlreadyIssuedException, without retrying, when the discriminator sees the valid-certificate index', function () {
    // The reflection technique, and the reasons for it, are IssueCertificateTest's
    // own — see that file's docblock. ReplaceStudentCertificateAction ships an
    // independent copy of the same method and gets the same direct proof.
    $action = app(ReplaceStudentCertificateAction::class);

    $previous = new PDOException("Duplicate entry 'x' for key 'uniq_valid_certificate_per_enrollment'");
    $previous->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'uniq_valid_certificate_per_enrollment'"];

    $exception = new UniqueConstraintViolationException(
        'mysql',
        'insert into `student_certificates` (...) values (...)',
        [],
        $previous,
    );
    $exception->setIndex('uniq_valid_certificate_per_enrollment');

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    expect(fn () => $method->invoke($action, $exception, 999))
        ->toThrow(CertificateAlreadyIssuedException::class);
});

it('signals retry — returns rather than throws — when the discriminator sees the reference index below the attempt bound', function () {
    $action = app(ReplaceStudentCertificateAction::class);

    $previous = new PDOException("Duplicate entry 'x' for key 'student_certificates_reference_number_unique'");
    $previous->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'student_certificates_reference_number_unique'"];

    $exception = new UniqueConstraintViolationException(
        'mysql',
        'insert into `student_certificates` (...) values (...)',
        [],
        $previous,
    );
    $exception->setIndex('student_certificates_reference_number_unique');

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    expect($method->invoke($action, $exception, 999))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Authorization — bespoke single-ability roles
|--------------------------------------------------------------------------
*/

it('denies an actor who does not hold replace_student_certificate', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $role = Role::findOrCreate('certificate-issuer-only-2', 'web');
    $role->syncPermissions(['issue_student_certificate']);

    $stranger = User::factory()->create(['is_active' => true]);
    $stranger->assignRole($role);

    expect(fn () => app(ReplaceStudentCertificateAction::class)->execute($stranger->refresh(), $this->enrollment))
        ->toThrow(AuthorizationException::class);

    expect(StudentCertificate::query()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('status', CertificateStatus::Valid)
        ->count())->toBe(1);
});

it('grants replacement to a bespoke role holding only replace_student_certificate', function () {
    app(IssueStudentCertificateAction::class)->execute($this->admin, $this->enrollment);

    $role = Role::findOrCreate('certificate-replacer-only', 'web');
    $role->syncPermissions(['replace_student_certificate']);

    $replacer = User::factory()->create(['is_active' => true]);
    $replacer->assignRole($role);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($replacer->refresh(), $this->enrollment);

    expect($replacement->status)->toBe(CertificateStatus::Valid);
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

it('logs both halves of a replacement against the actor — the old row updated and the new row created', function () {
    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    // The fixture's enrolment, NOT a new one: `enrollments` is UNIQUE on
    // (student_id, batch_id) and beforeEach already created the pair's row.
    $original = app(IssueStudentCertificateAction::class)->execute($actor, $this->enrollment);

    $replacement = app(ReplaceStudentCertificateAction::class)->execute($actor, $this->enrollment);

    $updated = Activity::query()
        ->where('subject_type', StudentCertificate::class)
        ->where('subject_id', $original->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($updated)->not->toBeNull('The replaced row was never logged as updated.')
        ->and($updated->causer_id === null ? null : (int) $updated->causer_id)->toBe($actor->getKey());

    $changes = $updated->attribute_changes;

    expect($changes->get('attributes')['status'] ?? null)->toBe('replaced')
        ->and($changes->get('old')['status'] ?? null)->toBe('valid');

    $created = Activity::query()
        ->where('subject_type', StudentCertificate::class)
        ->where('subject_id', $replacement->getKey())
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect($created)->not->toBeNull('The replacement row was never logged as created.')
        ->and($created->causer_id === null ? null : (int) $created->causer_id)->toBe($actor->getKey())
        ->and($created->attribute_changes->get('attributes')['replaces_certificate_id'] ?? null)
        ->toBe($original->getKey());
});
