<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "May this actor amend enrolments on this batch?" — asked once, answered here.
 *
 * The single statement of the rule spec line 110 describes. EnrollmentPolicy
 * consults it for the UI, and WithdrawEnrollmentAction consults it again under
 * lock; neither restates it, so the two cannot drift into disagreeing.
 *
 * WHY THE LOCKING FLAG EXISTS, AND WHY IT IS NOT COSMETIC
 * ------------------------------------------------------
 * MySQL runs at REPEATABLE READ. The first ordinary read in a transaction
 * establishes a snapshot, and every later ordinary read in that transaction is
 * served from it — including one that runs after a lockForUpdate() on some other
 * table. So an Action that reads anything before taking the batch mutex, and
 * then asks this question with an ordinary SELECT, gets an answer from BEFORE
 * the mutex was held. An assignment revoked in that window is invisible, and the
 * write proceeds on authorization that is already false.
 *
 * A locking read is not subject to the snapshot: it sees the latest committed
 * rows and holds them until the transaction ends. So the write path passes
 * locking: true and treats that answer as binding, while the UI passes the
 * default and gets a cheap, consistent-enough read.
 *
 * DATABASE-BACKED, NEVER A LOADED RELATION
 * ----------------------------------------
 * This deliberately does not read $batch->instructors. A loaded relationship is
 * a mutable property: it can be stale, and it can be replaced outright by any
 * code holding the model — `$batch->setRelation('instructors', collect([$me]))`
 * is authorization forged from the caller's own memory.
 */
final class EnrollmentUpdateRule
{
    public function allows(User $actor, int $batchId, bool $locking = false): bool
    {
        if ($actor->can('update_enrollment')) {
            return true;
        }

        if (! $actor->can('update_assigned_batch_enrollment')) {
            return false;
        }

        return $this->isAssigned($actor, $batchId, $locking);
    }

    /**
     * Read against the pivot table directly rather than through
     * Batch::instructors().
     *
     * This is a READ, so it is outside the write boundary ActionBoundaryArchTest
     * polices — that rule governs attach/detach/sync, and nothing here writes.
     * Going straight to the table keeps it to one statement with no model
     * hydration, which matters because the panel asks it on every render.
     */
    private function isAssigned(User $actor, int $batchId, bool $locking): bool
    {
        $query = DB::table('batch_instructor')
            ->where('batch_id', $batchId)
            ->where('user_id', $actor->getKey());

        return $locking
            ? $query->lockForUpdate()->exists()
            : $query->exists();
    }
}
