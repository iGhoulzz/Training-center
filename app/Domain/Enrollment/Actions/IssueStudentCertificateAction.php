<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletedException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Issue a certificate for a completed enrolment — the first of T5's three
 * transitions (design section 6.4).
 *
 * NOTHING -> VALID
 * -----------------
 * Requires the enrolment `completed` and no `valid` certificate already
 * standing against it. Snapshots `student_name`, `course_name` and
 * `completed_on` from the enrolment as it stands at THIS moment — see the
 * class docblock on StudentCertificate for why those columns deliberately do
 * not follow a later correction.
 *
 * THE SAME LOCK ORDER EVERY OTHER ENROLMENT ACTION USES
 * -------------------------------------------------------
 * EnrollmentMutex takes the batch, then the enrolment, extended one link
 * further here — batch -> enrolment -> certificate — exactly as
 * ReverseEnrollmentCompletionAction already does for the identical invariant
 * (at most one valid certificate implies a completed enrolment behind it).
 * Two concurrent issue attempts on the SAME enrolment are already serialised
 * by EnrollmentMutex's own enrolment-row lock; the certificate-row lock below
 * exists for a narrower but equally real reason — see hasValidCertificate().
 *
 * A LOCKING CHECK, NOT AN ORDINARY ONE
 * --------------------------------------
 * MySQL runs at REPEATABLE READ, and EnrollmentMutex::acquire()'s own FIRST
 * statement is an unlocked read that fixes this transaction's snapshot,
 * before the batch or enrolment lock is even requested. An ordinary SELECT
 * against `student_certificates` after that point would still be served from
 * that snapshot — stale with respect to anything another connection committed
 * while THIS transaction was blocked waiting for the batch lock. Only a
 * LOCKING read bypasses the snapshot and sees the truth. This is the exact
 * shape CompletionRule's own docblock documents for the scoped-permission
 * check, and CertificateConcurrencyTest proves it here the same way
 * CompletionConcurrencyTest proves it there: by removing the lock and
 * watching the assertion fail.
 *
 * RETRY IS DISCRIMINATED BY INDEX NAME, AND ONE OF THE TWO MUST NEVER RETRY
 * ---------------------------------------------------------------------------
 * Two unique indexes can raise ER_DUP_ENTRY out of the INSERT below, and they
 * mean opposite things:
 *
 *   - `student_certificates_reference_number_unique` — the randomly drawn
 *     reference collided with an existing one. Nothing about the REQUEST was
 *     wrong; a fresh draw fixes it, so the whole transaction retries, bounded
 *     by MAX_ATTEMPTS.
 *   - `uniq_valid_certificate_per_enrollment` — a valid certificate exists
 *     for this enrolment after all. This is the SAME refusal
 *     hasValidCertificate() exists to catch, arriving from the database
 *     instead of the lock — see that method's own docblock for why the lock
 *     does not make this branch unreachable in principle. Retrying it would
 *     not fix anything: the answer is still "one exists", forever. It must
 *     surface as CertificateAlreadyIssuedException on the first attempt, not
 *     loop.
 *
 * No raw PDO/driver exception ever reaches a caller — every UniqueConstraintViolationException
 * caught here either becomes a typed domain exception or is rethrown once the
 * two known shapes are ruled out, never swallowed silently.
 */
final class IssueStudentCertificateAction
{
    /** MySQL ER_DUP_ENTRY — belt and braces with catching UniqueConstraintViolationException specifically. */
    private const DUPLICATE_ENTRY = 1062;

    /**
     * Named exactly as the migration names it:
     * database/migrations/2026_08_27_000200_add_valid_enrollment_id_to_student_certificates_table.php.
     * A collision on THIS index is the "already issued" refusal and must never retry.
     */
    private const VALID_UNIQUE_INDEX = 'uniq_valid_certificate_per_enrollment';

    /**
     * Laravel's default naming for a `->unique()` column with no explicit
     * index name: `{table}_{column}_unique`. A collision here means the
     * randomly drawn reference is already taken — retry with a fresh draw.
     */
    private const REFERENCE_UNIQUE_INDEX = 'student_certificates_reference_number_unique';

    /**
     * Bounded so a pathological run of collisions fails loudly instead of
     * looping forever. CertificateReference draws from roughly 10^12
     * combinations (design section 6.5), so exhausting this in genuine
     * operation is not a real possibility — this is a backstop, not a limit
     * anyone is expected to hit.
     */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly CertificateReference $reference,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @throws EnrollmentNotCompletedException if the enrolment is not completed.
     * @throws CertificateAlreadyIssuedException if a valid certificate already stands.
     * @throws AuthorizationException if the actor may not issue certificates.
     */
    public function execute(User $actor, Enrollment $enrollment): StudentCertificate
    {
        Gate::forUser($actor)->authorize('issue', StudentCertificate::class);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->attempt($actor, $enrollment);
            } catch (UniqueConstraintViolationException $exception) {
                $this->rethrowUnlessReferenceCollision($exception, (int) $enrollment->getKey());
            }
        }

        /*
         * REACHED WHEN THE BUDGET IS GENUINELY EXHAUSTED, AND THAT IS THE POINT.
         *
         * An earlier version bounded the retry inside
         * rethrowUnlessReferenceCollision(), which made this line unreachable
         * IN PRINCIPLE rather than in practice: at the last attempt every
         * branch there threw, so a fifth reference collision surfaced the raw
         * UniqueConstraintViolationException — INSERT statement, bound student
         * name and all — to a caller, and StudentCertificateResource::refuse()
         * catches only the three domain exceptions, so it became a 500 carrying
         * the SQL. Plan line 912 and this class's own docblock both say no raw
         * driver error reaches a user; they were wrong about the code.
         *
         * The bound now lives in the loop above, where it belongs: the
         * discriminator decides what a collision MEANS, the loop decides how
         * many times to try. A developer diagnostic rather than a translated
         * message, matching Money's convention — five consecutive collisions on
         * a 31^8 alphabet is not a user-facing scenario, but it must not leak
         * the statement if it ever happens.
         */
        throw new RuntimeException(
            'Exhausted '.self::MAX_ATTEMPTS." certificate reference draws for enrolment [{$enrollment->getKey()}]."
        );
    }

    /**
     * Load the snapshot sources with a CURRENT read rather than a snapshot one.
     *
     * REVIEW FINDING, AND IT IS THE SAME BUG hasValidCertificate() ALREADY
     * GUARDS AGAINST. loadMissing() issues ordinary SELECTs, and an ordinary
     * SELECT inside this transaction is served from the REPEATABLE READ view
     * that EnrollmentMutex::acquire()'s own first statement fixed — before the
     * batch lock was even requested. So `student.full_name` and the course name
     * could be read as they stood BEFORE this transaction started waiting.
     *
     * The failing case is not exotic. Request A opens its transaction and blocks
     * on the batch mutex behind a long batch operation; meanwhile a corrected
     * spelling of the student's name commits on another connection. A acquires
     * the lock and snapshots the PRE-correction name onto a certificate that is
     * then printed and handed over — and for a replacement, that correction is
     * very often exactly why the operator clicked Replace.
     *
     * A SHARED lock, not lockForUpdate(). Both bypass the snapshot and read the
     * latest committed row, which is the whole requirement here; a shared lock
     * does it without blocking other readers of the same student or course, and
     * these rows are only ever read on this path.
     */
    private function loadSnapshotSources(Enrollment $locked): void
    {
        $locked->setRelation(
            'student',
            Student::query()->sharedLock()->findOrFail($locked->student_id),
        );

        $batch = Batch::query()->sharedLock()->findOrFail($locked->batch_id);

        $batch->setRelation(
            'course',
            Course::query()->sharedLock()->findOrFail($batch->course_id),
        );

        $locked->setRelation('batch', $batch);
    }

    private function attempt(User $actor, Enrollment $enrollment): StudentCertificate
    {
        return DB::transaction(function () use ($actor, $enrollment): StudentCertificate {
            $held = $this->mutex->acquire($enrollment);
            $locked = $held->enrollment;

            if ($locked->status !== EnrollmentStatus::Completed) {
                throw new EnrollmentNotCompletedException((int) $locked->getKey());
            }

            if ($this->hasValidCertificate($locked)) {
                throw new CertificateAlreadyIssuedException((int) $locked->getKey());
            }

            $this->loadSnapshotSources($locked);

            $issuedAt = CarbonImmutable::now();

            return $this->causers->withCauser(
                $actor,
                fn (): StudentCertificate => StudentCertificate::query()->create([
                    'enrollment_id' => $locked->getKey(),
                    'reference_number' => $this->reference->mint($issuedAt),
                    'student_name' => $locked->student->full_name,
                    'course_name' => $locked->batch->course->name(),
                    // The centre's calendar, not UTC — the same conversion
                    // every other human-facing document date in this system
                    // goes through (design section 8).
                    'completed_on' => CentreCalendar::localise($locked->completed_at)->toDateString(),
                    'issued_at' => $issuedAt,
                    'issued_by' => $actor->getKey(),
                    'status' => CertificateStatus::Valid,
                ]),
            );
        });
    }

    /**
     * A locking existence check against the real register, taken after the
     * enrolment is already locked.
     *
     * WHY THIS CANNOT BE PROVEN UNREACHABLE FROM THE ENROLMENT LOCK ALONE
     * ------------------------------------------------------------------------
     * EnrollmentMutex already serialises two Actions racing on the SAME
     * enrolment, which is what makes a genuine two-connection race resolve to
     * one winner (CertificateConcurrencyTest). This check exists on top of
     * that for the same reason ReverseEnrollmentCompletionAction's own
     * hasValidCertificate() does: it is what the database constraint's
     * discrimination logic in rethrowUnlessReferenceCollision() is a backstop
     * FOR, not a redundant restatement of the lock. Remove this method (or
     * its lockForUpdate()) and CertificateConcurrencyTest's lock-removal probe
     * fails — see that test file for the recorded failure.
     */
    private function hasValidCertificate(Enrollment $enrollment): bool
    {
        return StudentCertificate::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('status', CertificateStatus::Valid)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Decide what a unique-index collision out of the insert above means, and
     * either return (signalling "retry") or throw the correct refusal.
     *
     * @throws CertificateAlreadyIssuedException if the collision is on the one-valid-per-enrolment index.
     */
    private function rethrowUnlessReferenceCollision(
        UniqueConstraintViolationException $exception,
        int $enrollmentId,
    ): void {
        $isDuplicateEntry = ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY;

        if ($isDuplicateEntry && $exception->index === self::VALID_UNIQUE_INDEX) {
            throw new CertificateAlreadyIssuedException($enrollmentId);
        }

        if ($isDuplicateEntry && $exception->index === self::REFERENCE_UNIQUE_INDEX) {
            return;
        }

        throw $exception;
    }
}
