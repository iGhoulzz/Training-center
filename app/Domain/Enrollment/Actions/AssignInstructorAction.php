<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\InstructorNotEligibleException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Staff\Enums\EmploymentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Assign an instructor to a batch for a number of hours, or change the hours of
 * an instructor already assigned.
 *
 * Actor first and self-authorizing, like every other request-path Action here: a
 * policy only runs when something chooses to consult it, so putting the check
 * inside the Action makes the answer binding for the relation manager, a console
 * command, and any future API alike.
 *
 * AUTHORIZATION RUNS AGAINST THE LOCKED ROW, NOT A STALE COPY
 * -----------------------------------------------------------
 * The batch is re-read with lockForUpdate() INSIDE the transaction and the
 * policy is asked about THAT instance. Authorizing an instance read before the
 * lock is a real bug, not a stylistic one: a batch marked completed between the
 * caller's read and this write would still pass a check made against the older
 * copy, and hours would land on a closed batch. The lock also serializes two
 * concurrent assignments to the same batch, so they cannot interleave into a
 * duplicate row.
 *
 * WHY THE STATUS REFUSAL IS RE-ASKED AFTER THE POLICY DENIES
 * ----------------------------------------------------------
 * BatchPolicy::assignInstructor() folds two questions into one boolean: may this
 * actor assign instructors at all, and is this batch still open. A bare
 * authorize() would therefore report a closed batch as a 403, telling an
 * entitled administrator they lack a grant they in fact hold. So a denial is
 * re-examined: an actor who holds the ability and was refused was refused for
 * the batch's status, and gets the typed BatchClosedException. Everyone else
 * falls through to authorize(), which throws the ordinary AuthorizationException
 * — so an actor without the ability learns nothing about the batch's state.
 *
 * IDEMPOTENT BY CONSTRUCTION
 * --------------------------
 * syncWithoutDetaching() updates the pivot when the pair already exists and
 * leaves every other instructor on the batch alone. sync() would silently detach
 * the co-teacher; attach() would insert a second row for the same pair and only
 * the unique index would catch it, as a driver error.
 */
final class AssignInstructorAction
{
    /**
     * @throws BatchClosedException if the batch is completed or cancelled.
     * @throws InstructorNotEligibleException if the account is soft-deleted, is
     *                                        inactive, is not an instructor, or
     *                                        has no staff profile.
     */
    public function execute(User $actor, AssignInstructorData $data): Batch
    {
        return DB::transaction(function () use ($actor, $data): Batch {
            $batch = Batch::query()->lockForUpdate()->findOrFail($data->batchId);

            $this->authorize($actor, $batch);

            /*
             * withTrashed() so that a departed account is REFUSED rather than
             * merely not found. The two are different answers: findOrFail on a
             * scoped query reports a soft-deleted instructor as a nonexistent
             * one, which reads as a bad id and tells the caller nothing about
             * why the write will not happen. The eligibility check below names
             * the actual reason.
             *
             * lockForUpdate() on this row is not decoration either. The
             * eligibility decision READS is_active, deleted_at and the
             * employment type; reading them unlocked is the same race the batch
             * lock exists to prevent — a deactivation committed between this
             * read and the pivot write would leave hours assigned to somebody
             * the system had already stood down.
             */
            $instructor = User::query()->withTrashed()->lockForUpdate()->findOrFail($data->instructorId);

            $this->assertEligible($instructor);

            $batch->instructors()->syncWithoutDetaching([
                $instructor->getKey() => ['assigned_hours' => $data->assignedHours],
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Refuse the write, naming the actual reason.
     *
     * See the class docblock: the ability is checked before the status so that a
     * refusal never reveals a batch's state to somebody who may not touch it.
     */
    private function authorize(User $actor, Batch $batch): void
    {
        if (Gate::forUser($actor)->allows('assignInstructor', $batch)) {
            return;
        }

        if ($actor->can('assign_instructor') && ! $batch->acceptsEnrollments()) {
            throw new BatchClosedException((int) $batch->getKey());
        }

        // Throws AuthorizationException. Deliberately re-entered through the
        // Gate rather than thrown by hand, so the refusal carries whatever
        // response the policy produces rather than a message invented here.
        Gate::forUser($actor)->authorize('assignInstructor', $batch);
    }

    /**
     * Only a live, active account that actually teaches here may be assigned.
     *
     * trashed() is checked alongside is_active because a departed account and a
     * deactivated one are the same answer to the question this asks: not
     * somebody who can be put down as teaching a batch from now on. Batch's
     * instructors() relation deliberately still LISTS the departed, because the
     * hours they were already assigned are history phase 2 pays from; that is a
     * read, and this is a write.
     *
     * The staff profile is read through the relation query rather than the
     * loaded relation, so a stale in-memory copy on the passed instance cannot
     * make an administrator look like an instructor.
     */
    private function assertEligible(User $instructor): void
    {
        $profile = $instructor->staffProfile()->first();

        if (
            $instructor->trashed()
            || ! $instructor->is_active
            || $profile?->employment_type !== EmploymentType::Instructor
        ) {
            throw new InstructorNotEligibleException((int) $instructor->getKey());
        }
    }
}
