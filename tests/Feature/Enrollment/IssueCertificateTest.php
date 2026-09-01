<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletedException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use App\Support\CentreCalendar;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| IssueStudentCertificateAction (P3-T05)
|--------------------------------------------------------------------------
|
| The single-connection behaviour of the first of T5's three transitions.
| CertificateConcurrencyTest covers the two-connection race this file cannot
| prove.
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
});

/*
|--------------------------------------------------------------------------
| The happy path, and the snapshot proof
|--------------------------------------------------------------------------
*/

it('writes one valid row against a completed enrolment, snapshotting the student and course at that moment', function () {
    $enrollment = Enrollment::factory()
        ->for($this->student)
        ->for($this->batch)
        ->completed()
        ->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    expect($certificate)->toBeInstanceOf(StudentCertificate::class)
        ->and($certificate->status)->toBe(CertificateStatus::Valid)
        ->and($certificate->enrollment_id)->toBe($enrollment->getKey())
        ->and($certificate->student_name)->toBe('Amina Zarrouk')
        ->and($certificate->course_name)->toBe('Original Course Name')
        // THE LITERAL DATE, not a re-read of completed_at. The old assertion
        // compared against completed_at in UTC while the column is written in
        // Africa/Tripoli, so it was wrong 22:00-24:00 UTC every day. Comparing
        // against CentreCalendar::localise() here would have made the test
        // agree with the production code it is testing; a frozen clock and a
        // hard-coded date agree with neither.
        ->and($certificate->completed_on->toDateString())->toBe('2026-06-15')
        ->and($certificate->issued_by)->toBe($this->admin->getKey())
        ->and($certificate->reference_number)->toStartWith('TC-');

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())->toBe(1);

    /*
     * RENAMING THE COURSE AFTERWARDS DOES NOT CHANGE THE ALREADY-ISSUED ROW.
     *
     * The expected value here is the STRING captured before the rename — not
     * a fresh read of $certificate or of the course — so this cannot agree
     * with itself the way a self-referential assertion would.
     */
    $this->course->update(['name_en' => 'Renamed Course']);

    expect($certificate->fresh()->course_name)->toBe('Original Course Name');
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses issuance against an active enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->create();

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment))
        ->toThrow(EnrollmentNotCompletedException::class);

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->exists())->toBeFalse();
});

it('refuses issuance against a withdrawn enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->withdrawn()->create();

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment))
        ->toThrow(EnrollmentNotCompletedException::class);
});

it('refuses issuing twice against the same completed enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment))
        ->toThrow(CertificateAlreadyIssuedException::class);

    // ONE row, not two — the refusal did not insert anything alongside the
    // original.
    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())->toBe(1);
});

it('still refuses a second issuance after the first certificate was revoked', function () {
    // A revoked certificate is not "no certificate" — issuing again is a
    // REPLACE decision, not an ISSUE one. This is the same invariant
    // ReplaceCertificateTest and RevokeCertificateTest exercise from their
    // own side; this is the reverse-direction proof that Issue does not
    // silently treat a revoked row as room for a fresh issuance.
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    StudentCertificate::factory()->for($enrollment)->revoked()->create();

    // A revoked row is fine — no valid one exists, so this should actually
    // succeed. This is the CONTROL half of the assertion above: it proves the
    // check is genuinely reading `status = valid` rather than "any row
    // exists for this enrolment".
    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Valid);
});

/*
|--------------------------------------------------------------------------
| The outstanding balance never blocks issuance
|--------------------------------------------------------------------------
*/

it('succeeds for an enrolment carrying an outstanding balance', function () {
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    Charge::factory()->for($enrollment)->create(['list_price' => '750.000']);

    // The precondition this test is actually about: a REAL, non-zero
    // outstanding balance, read through the same production service the
    // issue form displays — not merely "a charge row exists".
    $outstandingBefore = app(ChargeQueryService::class)
        ->outstandingForEnrollment((int) $enrollment->getKey());

    expect($outstandingBefore)->not->toBeNull()
        ->and($outstandingBefore->toDecimal())->toBe('750.000');

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Valid);

    // And the balance is untouched — the Action has no code path that could
    // have consulted, let alone changed, it.
    $outstandingAfter = app(ChargeQueryService::class)
        ->outstandingForEnrollment((int) $enrollment->getKey());

    expect($outstandingAfter->toDecimal())->toBe('750.000');
});

/*
|--------------------------------------------------------------------------
| Authorization — bespoke single-ability roles
|--------------------------------------------------------------------------
*/

it('denies an actor who does not hold issue_student_certificate', function () {
    $role = Role::findOrCreate('certificate-viewer-only', 'web');
    $role->syncPermissions(['view_any_student_certificate']);

    $stranger = User::factory()->create(['is_active' => true]);
    $stranger->assignRole($role);

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($stranger->refresh(), $enrollment))
        ->toThrow(AuthorizationException::class);

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->exists())->toBeFalse();
});

it('grants issuance to a bespoke role holding only issue_student_certificate', function () {
    // The positive control for the refusal above: without it, a mistyped
    // permission name in the Action would leave the denial test green for
    // the wrong reason forever.
    $role = Role::findOrCreate('certificate-issuer-only', 'web');
    $role->syncPermissions(['issue_student_certificate']);

    $issuer = User::factory()->create(['is_active' => true]);
    $issuer->assignRole($role);

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($issuer->refresh(), $enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Valid);
});

/*
|--------------------------------------------------------------------------
| Retry discrimination — the reference index collides, and retries
|--------------------------------------------------------------------------
*/

it('retries the whole transaction and succeeds when the drawn reference collides', function () {
    /*
     * A COLLIDING REFERENCE IS PRE-SEEDED, THEN THE FIRST DRAW IS FORCED TO
     * MATCH IT.
     *
     * CertificateReference accepts an injected picker so the drawn characters
     * are deterministic. The picker below returns index 0 (character '2' —
     * the alphabet's first symbol) for the first mint() call's eight draws,
     * then the alphabet's LAST index for every draw after that — a different,
     * non-colliding reference on the retry.
     */
    $year = now()->year;
    $collidingReference = 'TC-'.$year.'-22222222';

    $otherEnrollment = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->for($otherEnrollment)->create(['reference_number' => $collidingReference]);

    $calls = 0;
    $alphabetLastIndex = strlen(CertificateReference::ALPHABET) - 1;

    $reference = new CertificateReference(function (int $max) use (&$calls, $alphabetLastIndex): int {
        $calls++;

        return $calls <= 8 ? 0 : $alphabetLastIndex;
    });

    app()->instance(CertificateReference::class, $reference);

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Valid)
        ->and($certificate->reference_number)->not->toBe($collidingReference)
        ->and($calls)->toBe(16, 'Expected exactly two mint() attempts (8 draws each): one collision, one retry that succeeded.');
});

/*
|--------------------------------------------------------------------------
| Retry discrimination — the valid-certificate index collides, and refuses
|--------------------------------------------------------------------------
|
| A DIFFERENT test from the one above, deliberately — design section 6.5: "a
| forced reference collision retries and succeeds; a forced valid-certificate
| collision refuses without retrying" are two distinct claims, and one test
| cannot tell them apart.
|
| WHY THIS IS A REFLECTION TEST, NOT AN END-TO-END ONE — MEASURED, NOT ASSUMED
| ------------------------------------------------------------------------------
| An earlier version of this test tried to force the collision by racing a
| second connection's raw insert against the check's own SELECT ... FOR
| UPDATE. Measured against this project's MySQL: that SELECT takes a GAP LOCK
| on the enrolment_id range it scans (InnoDB next-key locking under
| REPEATABLE READ), so a second connection's insert for the same
| enrollment_id does not collide — it BLOCKS, for the full 50-second
| innodb_lock_wait_timeout, then fails on a lock-wait error that has nothing
| to do with the discrimination logic under test. A same-connection injection
| fares no better: anything inserted inside the Action's own transaction is
| rolled back together with it the moment the real insert collides and the
| exception propagates out of DB::transaction() — which is why an earlier
| version of this test asserted a row existed that had, in fact, been rolled
| back with everything else.
|
| Both failures point at the same fact CertificateConcurrencyTest's own class
| docblock already states: with the lock correctly in place, TWO real
| connections can never actually collide on uniq_valid_certificate_per_enrollment
| — the gap lock closes the window before either the "second connection" or
| the "same connection, later" version of this scenario can occur. The
| VALID_UNIQUE_INDEX branch in rethrowUnlessReferenceCollision() is therefore
| defence-in-depth for a case that is unreachable through the Action's own
| write path while the lock holds — exactly what that method's docblock and
| IssueStudentCertificateAction's class docblock both say. Proving its LOGIC
| is correct, independent of whether today's lock happens to make it
| reachable, is what a direct call buys that no amount of racing real
| connections can: PrivateFileAccessTest's redirectTo() test is this
| codebase's existing precedent for reaching a private method this way.
*/

it('refuses via CertificateAlreadyIssuedException, without retrying, when the discriminator sees the valid-certificate index', function () {
    $action = app(IssueStudentCertificateAction::class);

    $exception = uniqueConstraintViolation('uniq_valid_certificate_per_enrollment');

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    // This call must THROW, not return. A bug that keyed the branch on the
    // attempt bound rather than on the index name would let it through as a
    // retry signal.
    expect(fn () => $method->invoke($action, $exception, 999))
        ->toThrow(CertificateAlreadyIssuedException::class);
});

it('mints exactly once when the enrolment already holds a valid certificate — the Action does not retry', function () {
    /*
     * REVIEW FINDING: "WITHOUT RETRYING" WAS INFERRED, NOT OBSERVED.
     *
     * The test above proves the discriminator THROWS for the valid-certificate
     * index. It says nothing about execute()'s loop, so a defect there — a
     * catch that swallowed CertificateAlreadyIssuedException and looped, a
     * swapped catch order — would leave it green. The Done-when's claim is
     * about the ACTION's behaviour, so the Action is what this drives.
     *
     * The counter is the discriminator. A retry costs a second mint() and
     * therefore a second batch of 8 draws; exactly 8 means one attempt was
     * made and the refusal was final.
     */
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    $calls = 0;

    app()->instance(CertificateReference::class, new CertificateReference(function (int $max) use (&$calls): int {
        $calls++;

        return random_int(0, $max);
    }));

    expect(fn () => app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment))
        ->toThrow(CertificateAlreadyIssuedException::class);

    expect($calls)->toBeLessThanOrEqual(
        8,
        'The Action drew more than one reference for an enrolment that already holds a valid certificate, '
        .'which means the refusal was retried instead of being final.',
    );

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())
        ->toBe(1, 'The refused second issuance left a row behind.');
});

it('signals retry — returns rather than throws — when the discriminator sees the reference index below the attempt bound', function () {
    // The positive control for the test above: the SAME method, given the
    // OTHER index name, must NOT throw at all — it returns, which is what
    // execute()'s loop reads as "draw again". Proves the branch is keyed on
    // the index name, not on some property both calls happen to share.
    $action = app(IssueStudentCertificateAction::class);

    $exception = uniqueConstraintViolation('student_certificates_reference_number_unique');

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    expect($method->invoke($action, $exception, 999))->toBeNull();
});

it('surfaces a typed diagnostic, never the raw driver exception, when every draw collides', function () {
    /*
     * REVIEW FINDING, AND THE PLAN DECIDED IT.
     *
     * The discriminator used to carry the retry bound itself
     * (`... && $attempt < self::MAX_ATTEMPTS`), which meant that on the final
     * attempt every branch threw and the RAW UniqueConstraintViolationException
     * reached the caller — INSERT statement, bound student name and all.
     * StudentCertificateResource::refuse() catches only the three domain
     * exceptions, so that was a 500 carrying the SQL. Plan line 912 says no raw
     * driver error reaches a user, so the bound moved to execute()'s loop.
     *
     * This drives the REAL Action end to end with a picker that always draws
     * the same reference, so every attempt genuinely collides on the reference
     * index. What must NOT come out is UniqueConstraintViolationException.
     */
    $fixedReference = 'TC-'.CentreCalendar::yearOf(now()).'-22222222';

    $otherEnrollment = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->for($otherEnrollment)->create(['reference_number' => $fixedReference]);

    $calls = 0;

    app()->instance(CertificateReference::class, new CertificateReference(function (int $max) use (&$calls): int {
        $calls++;

        return 0;
    }));

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $thrown = null;

    try {
        app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull('The Action succeeded when every reference draw was forced to collide.')
        ->and($thrown)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->not->toContain('insert into')
        ->and($calls)->toBe(40, 'Expected exactly MAX_ATTEMPTS (5) mint() attempts of 8 draws each.');

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())
        ->toBe(0, 'A failed issuance must leave no row behind.');
});

it('still rethrows a reference collision as a retry signal below the bound, which the loop owns', function () {
    // The positive control for the test above: the discriminator itself no
    // longer knows about attempts at all, so the SAME index name it now always
    // treats as retryable must return rather than throw. If this throws, the
    // bound has crept back into the discriminator and the test above would be
    // passing for the wrong reason.
    $action = app(IssueStudentCertificateAction::class);

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    expect($method->invoke($action, uniqueConstraintViolation('student_certificates_reference_number_unique'), 999))
        ->toBeNull();
});

it('rethrows the original exception unchanged for an unrecognised index', function () {
    // Neither known index — a collision this Action does not know how to
    // interpret must surface as itself, never be swallowed or mistranslated
    // into either typed refusal.
    $action = app(IssueStudentCertificateAction::class);

    $exception = uniqueConstraintViolation('some_other_unique_index');

    $method = new ReflectionMethod($action, 'rethrowUnlessReferenceCollision');

    expect(fn () => $method->invoke($action, $exception, 999))
        ->toThrow(UniqueConstraintViolationException::class);
});

/**
 * A UniqueConstraintViolationException shaped exactly as MySQL's driver
 * produces one — ER_DUP_ENTRY (1062) in errorInfo, and the named index set
 * via setIndex() the same way Laravel's own MySqlConnection::
 * parseUniqueConstraintViolation() sets it. Built directly rather than
 * provoked from a real query, for the reasons the test file docblock above
 * gives in full.
 */
function uniqueConstraintViolation(string $index): UniqueConstraintViolationException
{
    $previous = new PDOException("Duplicate entry 'x' for key '{$index}'");
    $previous->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key '{$index}'"];

    $exception = new UniqueConstraintViolationException(
        'mysql',
        'insert into `student_certificates` (...) values (...)',
        [],
        $previous,
    );

    return $exception->setIndex($index);
}

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

it('writes a created activity entry attributed to the issuing actor', function () {
    $issuer = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($issuer, 'admin');

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($issuer, $enrollment);

    $activity = Activity::query()
        ->where('subject_type', StudentCertificate::class)
        ->where('subject_id', $certificate->getKey())
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull('Issuance wrote no activity entry at all.')
        ->and($activity->causer_id === null ? null : (int) $activity->causer_id)
        ->toBe($issuer->getKey(), 'The issuance was logged with no causer, or with the wrong one.');

    // The diff must actually carry the decision, not merely exist.
    $attributes = $activity->attribute_changes->get('attributes') ?? [];

    expect($attributes['status'] ?? null)->toBe('valid')
        ->and($attributes['issued_by'] ?? null)->toBe($issuer->getKey());
});

it('attributes the issuance to the Action actor rather than to whoever is logged in', function () {
    /*
     * THE POSITIVE CONTROL THAT SEPARATES "an actor was recorded" FROM "the
     * RIGHT actor was recorded". A session belonging to someone else is active
     * throughout; the causer must still be the actor the Action was handed, or
     * the audit trail names the wrong person on every certificate issued
     * through any background or delegated path.
     */
    $sessionUser = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($sessionUser, 'admin');

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $this->actingAs($sessionUser);

    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($actor, $enrollment);

    $causerId = Activity::query()
        ->where('subject_type', StudentCertificate::class)
        ->where('subject_id', $certificate->getKey())
        ->where('event', 'created')
        ->latest('id')
        ->value('causer_id');

    expect($causerId === null ? null : (int) $causerId)->toBe($actor->getKey())
        ->and($causerId === null ? null : (int) $causerId)->not->toBe($sessionUser->getKey());
});
