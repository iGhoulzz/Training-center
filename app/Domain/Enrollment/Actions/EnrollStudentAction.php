<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\DuplicateEnrollmentException;
use App\Domain\Enrollment\Exceptions\StudentNotEnrollableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Put a student on a batch.
 *
 * Actor first and self-authorizing, like every other request-path Action here: a
 * policy only runs when something chooses to consult it, so the check lives
 * inside the Action and binds the relation manager, a console command and any
 * future API alike.
 *
 * THE ABILITY IS CHECKED BEFORE THE BATCH'S STATUS
 * ------------------------------------------------
 * Unlike AssignInstructorAction, the create ability is record-independent, so no
 * denial has to be re-examined: authorize() runs first and an actor without
 * create_enrollment learns nothing about the batch. Only once they are entitled
 * does the closed-batch refusal become visible to them.
 *
 * CREATION IS NOT SCOPED TO BATCHES THE ACTOR TEACHES
 * ---------------------------------------------------
 * Deliberate, and different from the update rule. Spec line 15 requires a
 * front-desk staffer to enrol a walk-in, and front-desk staff teach nothing;
 * scoping creation would make the system's stated purpose unreachable for the
 * people it was written for. Editing IS scoped — see EnrollmentPolicy::update().
 *
 * EVERYTHING THE DECISION READS IS LOCKED
 * ---------------------------------------
 * The batch's status and the student's deleted state are both read inside the
 * transaction under lockForUpdate(), because a batch closed or a student deleted
 * between an unlocked read and the insert would commit anyway. P1-T10d is the
 * cautionary tale: locking one row says nothing about another table's.
 *
 * THE `ENR-` REFERENCE IS WRITTEN TWICE, IN ONE TRANSACTION
 * --------------------------------------------------------
 * `enrollments.reference` is NOT NULL UNIQUE and contains the row's own id, so
 * its final value is unknowable until the row exists and the row cannot be
 * inserted without one. MySQL forbids a generated column from referencing an
 * AUTO_INCREMENT column, so the stored-generated approach used elsewhere in this
 * schema is unavailable.
 *
 * The insert therefore carries Reference::placeholder() — a unique UUID, unique
 * because the column is — and the row is updated to its real `ENR-` value before
 * this transaction commits (design section 2). Both writes commit together, so
 * no other connection ever observes a placeholder and the constraint holds the
 * whole way through.
 *
 * The replacement is deliberately NOT deferred to a `created` model listener or
 * an afterCommit hook. A listener would run outside any transaction this Action
 * controls, and an afterCommit hook runs after the placeholder is already
 * visible — which is the one thing the placeholder mechanism exists to prevent.
 *
 * `reference` is excluded from Enrollment::auditedAttributes(), which is what
 * keeps the placeholder out of the append-only activity log. The reasoning is
 * recorded on the model, next to the exclusion itself.
 */
final class EnrollStudentAction
{
    /**
     * MySQL ER_DUP_ENTRY.
     */
    private const DUPLICATE_ENTRY = 1062;

    /**
     * The index that carries "one enrolment per student per batch".
     *
     * Laravel's default name for unique(['student_id', 'batch_id']) on this
     * table. EnrollmentTest asserts this constant matches an index that actually
     * exists, so a migration renaming it fails the build rather than silently
     * turning every duplicate into a raw driver error.
     */
    private const DUPLICATE_INDEX = 'enrollments_student_id_batch_id_unique';

    /**
     * @throws BatchClosedException if the batch is completed or cancelled.
     * @throws StudentNotEnrollableException if the student record is soft-deleted.
     * @throws DuplicateEnrollmentException if the student is already on the batch.
     */
    public function execute(User $actor, EnrollStudentData $data): Enrollment
    {
        return DB::transaction(function () use ($actor, $data): Enrollment {
            Gate::forUser($actor)->authorize('create', Enrollment::class);

            $batch = Batch::query()->lockForUpdate()->findOrFail($data->batchId);

            if (! $batch->acceptsEnrollments()) {
                throw new BatchClosedException((int) $batch->getKey());
            }

            /*
             * withTrashed() so a departed student is REFUSED rather than merely
             * not found: findOrFail on a scoped query reports a soft-deleted
             * student as a bad id, which tells the caller nothing about why the
             * write will not happen.
             */
            $student = Student::query()->withTrashed()->lockForUpdate()->findOrFail($data->studentId);

            if ($student->trashed()) {
                throw new StudentNotEnrollableException((int) $student->getKey());
            }

            $exists = Enrollment::query()
                ->where('student_id', $data->studentId)
                ->where('batch_id', $data->batchId)
                ->exists();

            if ($exists) {
                throw new DuplicateEnrollmentException($data->studentId, $data->batchId);
            }

            /*
             * Capacity is intentionally not enforced. Over-enrolment surfaces
             * through Batch::isOverCapacity() as a warning, per spec line 217.
             */
            try {
                $enrollment = Enrollment::create([
                    'student_id' => $data->studentId,
                    'batch_id' => $data->batchId,
                    'enrolled_at' => now(),
                    'status' => EnrollmentStatus::Active,
                    /*
                     * Not the real reference — that needs the id this insert is
                     * about to mint. Replaced below, before this transaction
                     * commits. See the class docblock.
                     */
                    'reference' => Reference::placeholder(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                /*
                 * Lost the race. The batch lock serializes two requests enrolling
                 * into the SAME batch, so this is reachable by a write that did
                 * not take that lock — a seeder, a console command, a repair
                 * script.
                 *
                 * BOTH THE CODE AND THE INDEX ARE CHECKED. 1062 alone means "some
                 * unique index refused this row", which is not the same statement
                 * as "this student is already on this batch". A second unique
                 * index added to this table later would start reporting its own
                 * violations as duplicate enrolments — a confident, wrong message
                 * about a constraint nobody was thinking about. Naming the index
                 * makes the conversion say only what it knows, for the same reason
                 * DeleteCourseAction converts only 1451.
                 */
                $isDuplicatePair = ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
                    && $exception->index === self::DUPLICATE_INDEX;

                if (! $isDuplicatePair) {
                    throw $exception;
                }

                throw new DuplicateEnrollmentException($data->studentId, $data->batchId);
            }

            /*
             * The placeholder's whole lifetime, and it ends here.
             *
             * OUTSIDE the catch above on purpose. That catch converts a 1062 on
             * one named index into "this student is already on this batch", and
             * a 1062 raised by THIS statement would be a collision on
             * enrollments_reference_unique — a different constraint, about which
             * that message would be confidently wrong. It is left to surface as
             * itself.
             */
            $enrollment->update([
                'reference' => Reference::format(
                    Reference::ENROLLMENT_PREFIX,
                    /*
                     * THE CENTRE'S CALENDAR, NOT UTC, AND NOT A LOCAL COPY OF
                     * THAT DECISION. Reference::format() takes a year precisely
                     * so it owns no timezone policy — the caller does, because
                     * only the caller knows which column dates the document, and
                     * for an enrolment that is `enrolled_at`. Which calendar to
                     * read it on is CentreCalendar's, shared with the backfill
                     * migration so the two cannot produce different references
                     * for rows nobody can tell apart (design section 8).
                     *
                     * The defensive copy this line used to spell out is inside
                     * CentreCalendar::localise() now. It is still required and
                     * still made: the `datetime` cast yields a MUTABLE Carbon
                     * whose setTimezone() changes the instance in place, so
                     * converting it directly would hand the caller back an
                     * enrolment whose enrolled_at silently reads in Tripoli.
                     * CarbonImmutable::instance() copies first, so the attribute
                     * below is untouched — and every future caller inherits that
                     * rather than having to remember it.
                     */
                    CentreCalendar::yearOf($enrollment->enrolled_at),
                    (int) $enrollment->getKey(),
                ),
            ]);

            return $enrollment;
        });
    }
}
