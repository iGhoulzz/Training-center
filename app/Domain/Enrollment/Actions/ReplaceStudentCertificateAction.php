<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException;
use App\Domain\Enrollment\Exceptions\CertificateChangedException;
use App\Domain\Enrollment\Exceptions\CertificateReferenceExhaustedException;
use App\Domain\Enrollment\Exceptions\NoValidCertificateException;
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
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Reissue a corrected certificate, superseding the one currently valid
 * (design section 6.4) — T5's second transition.
 *
 * VALID -> REPLACED, AND A NEW ROW -> VALID, IN ONE TRANSACTION
 * -----------------------------------------------------------------
 * Requires a `valid` certificate to exist. The old row moves to `replaced`
 * FIRST, inside this transaction, so the generated `valid_enrollment_id`
 * column recomputes to NULL and frees the unique slot BEFORE the new row is
 * inserted — attempting the insert first would collide with the row it is
 * meant to supersede. A failed insert rolls the whole transaction back,
 * leaving the original row `valid` rather than leaving the enrolment with
 * none — ReplaceCertificateTest proves this with a forced failure on the
 * insert.
 *
 * THE NEW ROW'S replaces_certificate_id POINTS BACKWARDS
 * ---------------------------------------------------------
 * Onto the row just marked `replaced`, matching StudentCertificate::replaces()'s
 * own docblock: the pointer lives on the new row because the old row's id is
 * known before the new row exists, while the reverse would not be.
 *
 * THE SNAPSHOT IS RE-TAKEN, NOT COPIED FROM THE OLD ROW
 * ----------------------------------------------------------
 * `student_name`, `course_name` and `completed_on` are re-derived from the
 * enrolment as it stands NOW, exactly as IssueStudentCertificateAction takes
 * them. This is a deliberate reading of design section 6.4's "the physical
 * certificate the student was handed" — a replacement exists specifically to
 * correct what the previous document said, most commonly a misspelled name or
 * a wrong course credited, so the new row has to be able to disagree with the
 * old one on those columns. Copying the old snapshot verbatim would make a
 * name correction impossible to issue through this Action at all. `completed_on`
 * cannot actually differ in practice: a `valid` certificate blocks
 * ReverseEnrollmentCompletionAction (T3's CompletionNotReversibleException::certificateStillValid),
 * so the enrolment's `completed_at` cannot have moved since the original
 * issuance while a valid row still stands.
 *
 * THE SAME LOCK ORDER AND THE SAME RETRY DISCRIMINATION AS ISSUE
 * -------------------------------------------------------------------
 * See IssueStudentCertificateAction's own docblock for both — the reasoning is
 * identical here: EnrollmentMutex's enrolment lock, then a LOCKING read of
 * `student_certificates` (this time for the row itself, not just its
 * existence, since it has to be updated), and a reference collision retries
 * while a uniq_valid_certificate_per_enrollment collision never does. The
 * latter is a near-impossible outcome for THIS Action specifically — the old
 * row is already marked `replaced`, in this same transaction, before the
 * insert runs.
 *
 * rethrowUnlessReferenceCollision() BELOW IS A SEPARATE COPY, NOT A SHARED ONE
 * ---------------------------------------------------------------------------------
 * There is no common base class between this Action and
 * IssueStudentCertificateAction, so each carries its own private copy of the
 * discrimination logic. ReplaceCertificateTest proves THIS copy directly —
 * both the reference-collision retry and the valid-index refusal — with the
 * identical reflection technique IssueCertificateTest uses on the other one,
 * rather than assuming one proof stands in for both.
 */
final class ReplaceStudentCertificateAction
{
    /** MySQL ER_DUP_ENTRY. */
    private const DUPLICATE_ENTRY = 1062;

    /** See IssueStudentCertificateAction's identical constant. */
    private const VALID_UNIQUE_INDEX = 'uniq_valid_certificate_per_enrollment';

    /** See IssueStudentCertificateAction's identical constant. */
    private const REFERENCE_UNIQUE_INDEX = 'student_certificates_reference_number_unique';

    /** See IssueStudentCertificateAction's identical constant. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly CertificateReference $reference,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @throws NoValidCertificateException if no valid certificate stands to replace.
     * @throws CertificateAlreadyIssuedException if the insert collides on uniq_valid_certificate_per_enrollment.
     * @throws CertificateChangedException if $expectedCertificateId is no longer the current valid row.
     * @throws CertificateReferenceExhaustedException if every bounded reference draw collided.
     * @throws AuthorizationException if the actor may not replace certificates.
     */
    public function execute(User $actor, Enrollment $enrollment, ?int $expectedCertificateId = null): StudentCertificate
    {
        Gate::forUser($actor)->authorize('replace', StudentCertificate::class);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->attempt($actor, $enrollment, $expectedCertificateId);
            } catch (UniqueConstraintViolationException $exception) {
                $this->rethrowUnlessReferenceCollision($exception, (int) $enrollment->getKey());
            }
        }

        throw new CertificateReferenceExhaustedException(
            (int) $enrollment->getKey(),
            self::MAX_ATTEMPTS,
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

    private function attempt(User $actor, Enrollment $enrollment, ?int $expectedCertificateId): StudentCertificate
    {
        return DB::transaction(function () use ($actor, $enrollment, $expectedCertificateId): StudentCertificate {
            $held = $this->mutex->acquire($enrollment);
            $locked = $held->enrollment;

            $current = $this->lockCurrentValidCertificate($locked);

            if ($current === null) {
                throw new NoValidCertificateException((int) $locked->getKey());
            }

            /*
             * THE ROW THE ACTOR CLICKED, RE-CHECKED UNDER THE LOCK.
             *
             * $expectedCertificateId is null for callers that genuinely mean
             * "whatever is current". The panel is not one of them: its row
             * actions are clicked on a SPECIFIC certificate, and between the
             * modal opening and the actor submitting it, somebody else can
             * replace that row — leaving this Action to resolve a DIFFERENT
             * certificate and act on it under the first actor's intent. See
             * CertificateChangedException.
             *
             * Checked here, inside the transaction and after the locking read,
             * because anywhere earlier is a snapshot answer to a question that
             * only the lock can settle.
             */
            if ($expectedCertificateId !== null && (int) $current->getKey() !== $expectedCertificateId) {
                throw new CertificateChangedException(
                    (int) $locked->getKey(),
                    $expectedCertificateId,
                    (int) $current->getKey(),
                );
            }

            $this->loadSnapshotSources($locked);

            $issuedAt = CarbonImmutable::now();

            return $this->causers->withCauser(
                $actor,
                function () use ($actor, $locked, $current, $issuedAt): StudentCertificate {
                    // OLD ROW -> REPLACED FIRST. This is what frees the
                    // generated column's unique slot before the insert below
                    // ever runs — see the class docblock.
                    $current->update(['status' => CertificateStatus::Replaced]);

                    return StudentCertificate::query()->create([
                        'enrollment_id' => $locked->getKey(),
                        'reference_number' => $this->reference->mint($issuedAt),
                        'student_name' => $locked->student->full_name,
                        'course_name' => $locked->batch->course->name(),
                        'completed_on' => CentreCalendar::localise($locked->completed_at)->toDateString(),
                        'issued_at' => $issuedAt,
                        'issued_by' => $actor->getKey(),
                        'status' => CertificateStatus::Valid,
                        'replaces_certificate_id' => $current->getKey(),
                    ]);
                },
            );
        });
    }

    /**
     * A locking read of the row itself, not merely its existence — the row
     * has to be updated to `replaced`, so `first()` rather than `exists()`
     * (contrast IssueStudentCertificateAction::hasValidCertificate(), which
     * has nothing to mutate and only needs the boolean). See that method's
     * docblock for why this must be a LOCKING read rather than an ordinary
     * one: the same REPEATABLE READ snapshot hazard applies here.
     */
    private function lockCurrentValidCertificate(Enrollment $enrollment): ?StudentCertificate
    {
        return StudentCertificate::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('status', CertificateStatus::Valid)
            ->lockForUpdate()
            ->first();
    }

    /**
     * See IssueStudentCertificateAction's identical method.
     *
     * A VALID_UNIQUE_INDEX collision here throws CertificateAlreadyIssuedException,
     * NOT NoValidCertificateException — despite this Action's own precondition
     * failure being NoValidCertificateException. The two guard different
     * facts: the precondition check above means "no valid row exists to
     * replace"; a collision here means the OPPOSITE — the insert found a
     * valid row already occupying the slot, which is the same fact
     * IssueStudentCertificateAction refuses under the same name. Reusing that
     * exception rather than minting a Replace-specific one keeps "a valid
     * certificate already stands" a single message regardless of which
     * Action's insert discovered it.
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
