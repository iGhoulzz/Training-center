<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;

/**
 * Take the batch mutex, then the enrolment row, and prove they belong together.
 *
 * Used by WithdrawEnrollmentAction and DeleteEnrollmentAction. Both must run
 * inside a transaction the caller opened; this does not open one, because the
 * lock is worthless outside the caller's own transaction and hiding that here
 * would make it look handled.
 *
 * THE BATCH ID COMES FROM THE DATABASE, NOT FROM THE PASSED MODEL
 * ---------------------------------------------------------------
 * $enrollment->batch_id reads an in-memory attribute, and a caller can assign to
 * it without ever saving — `$enrollment->batch_id = 999` is a dirty instance,
 * not a persisted change. Trusting it sends the mutex to some other batch, and
 * the row then gets written under a lock nobody holds. That is worse than taking
 * no lock, because every "a batch lock was taken" assertion still passes: one
 * was. The wrong one.
 *
 * "The association is immutable" is a statement about what gets PERSISTED. It
 * says nothing about what an object in memory is carrying.
 *
 * THE FIRST LOOKUP SELECTS A MODEL, NOT A SCALAR
 * ----------------------------------------------
 * findOrFail() rather than value('batch_id'). A missing enrolment must report
 * itself as a missing ENROLMENT: `(int) null` is 0, and `Batch::findOrFail(0)`
 * reports a missing Batch, sending whoever reads the log looking for the wrong
 * record entirely.
 *
 * BATCH BEFORE ENROLMENT, ALWAYS
 * ------------------------------
 * Matching EnrollStudentAction's order. A consistent global lock order is what
 * stops two Actions deadlocking on the same pair, and a deadlock is a 500 for
 * whichever request loses.
 */
final class EnrollmentMutex
{
    /**
     * @throws EnrollmentBatchChangedException if the enrolment moved between the
     *                                         lookup and the locked reload.
     */
    public function acquire(Enrollment $enrollment): LockedEnrollment
    {
        $persisted = Enrollment::query()
            ->select(['id', 'batch_id'])
            ->findOrFail($enrollment->getKey());

        $batchId = (int) $persisted->batch_id;

        Batch::query()->lockForUpdate()->findOrFail($batchId);

        $locked = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->getKey());

        /*
         * REVALIDATE UNDER THE LOCK. The lookup above was unlocked — it had to
         * be, since it is what chooses which row to lock — so it proves nothing
         * by itself. This proves the batch now held is the batch the locked
         * enrolment actually belongs to.
         *
         * Nothing in phase 1 moves an enrolment between batches, so this cannot
         * fire today. It is here because "cannot happen" is a property that has
         * to be ENFORCED rather than assumed: if a later task adds a re-parenting
         * path, this is what stops a write committing under the wrong mutex.
         */
        if ((int) $locked->batch_id !== $batchId) {
            throw new EnrollmentBatchChangedException(
                (int) $locked->getKey(),
                $batchId,
                (int) $locked->batch_id,
            );
        }

        return new LockedEnrollment($batchId, $locked);
    }
}
