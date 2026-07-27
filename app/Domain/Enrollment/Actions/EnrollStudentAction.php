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
use App\Models\User;
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
                return Enrollment::create([
                    'student_id' => $data->studentId,
                    'batch_id' => $data->batchId,
                    'enrolled_at' => now(),
                    'status' => EnrollmentStatus::Active,
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
        });
    }
}
