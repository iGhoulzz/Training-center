<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Exceptions\BatchInUseException;
use App\Domain\Enrollment\Models\Batch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a batch, refusing while anything still references it.
 *
 * The sibling of DeleteCourseAction, gated the same way for the same reason:
 * BatchPolicy::delete() documents that the "refuse a batch that has enrolments"
 * rule belongs in the FOREIGN KEY rather than a policy check, because a policy
 * check races an enrolment created between the check and the delete and a
 * constraint cannot be raced.
 *
 * TWO CONSTRAINTS, ONE REFUSAL. Since P1-T11, both enrollments.batch_id and
 * batch_instructor.batch_id restrict. The pre-check names both because either is
 * a correct reason to refuse — the batch still carries history somebody is owed
 * money for, or was taught under.
 *
 * The pre-check exists only for a readable message. The foreign keys are what
 * actually guarantee the history survives.
 */
final class DeleteBatchAction
{
    /**
     * MySQL: "Cannot delete or update a parent row: a foreign key constraint
     * fails". The only database error that means "still in use" — everything
     * else is a real failure and must surface as one.
     */
    private const FOREIGN_KEY_RESTRICTED = 1451;

    /**
     * @throws BatchInUseException if enrolments or instructor allocations remain.
     */
    public function execute(User $actor, Batch $batch): void
    {
        Gate::forUser($actor)->authorize('delete', $batch);

        if ($batch->enrollments()->exists() || $batch->instructors()->exists()) {
            throw new BatchInUseException((int) $batch->getKey());
        }

        try {
            DB::transaction(fn () => $batch->delete());
        } catch (QueryException $exception) {
            /*
             * Lost the race: an enrolment or allocation appeared between the
             * check and the delete.
             *
             * Only 1451 is converted. Catching QueryException wholesale would
             * report a connection failure, a deadlock or a disk-full error as
             * "this batch is still in use" — a reassuring message about a
             * completely different problem, and the kind of mistranslation that
             * hides an outage.
             */
            if (($exception->errorInfo[1] ?? null) === self::FOREIGN_KEY_RESTRICTED) {
                throw new BatchInUseException((int) $batch->getKey());
            }

            throw $exception;
        }
    }
}
