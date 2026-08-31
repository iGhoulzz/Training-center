<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "May this actor mark completion on this batch?" — asked once, answered here.
 *
 * MODELLED ON EnrollmentUpdateRule, BUT DELIBERATELY A SEPARATE CLASS
 * ---------------------------------------------------------------------
 * EnrollmentUpdateRule answers a different question — whether the actor may
 * amend an enrolment at all, gated by `update_enrollment` /
 * `update_assigned_batch_enrollment`. Completion is its own act with its own
 * permission pair (spec section 5.1): an actor who may withdraw or edit an
 * enrolment does not thereby get to certify that the student finished, and the
 * reverse holds too — an instructor who marks a batch complete each term should
 * not need editing rights they have no other use for. Parametrising one class
 * over "which two permission strings" would couple two axes of authorization
 * that the spec keeps apart on purpose, and would make a future change to one
 * question silently reachable from the call site of the other.
 *
 * CompleteEnrollmentAction and ReverseEnrollmentCompletionAction both consult
 * this; neither restates the rule, so the two cannot drift into disagreeing
 * about who may mark or unmark completion on a given batch.
 *
 * THE SAME THREE-STEP SHAPE, FOR THE SAME REASON
 * ------------------------------------------------
 * 1. Unrestricted `complete_enrollment` — super admin and admin.
 * 2. Else the scoped `complete_assigned_batch_enrollment` — staff, gated by
 *    whether the actor actually teaches this batch.
 * 3. The scoping question is answered by a **locking** read of the
 *    `batch_instructor` pivot, taken by the caller AFTER `EnrollmentMutex` is
 *    acquired.
 *
 * WHY THE LOCKING FLAG EXISTS, AND WHY IT IS NOT COSMETIC
 * ------------------------------------------------------
 * MySQL runs at REPEATABLE READ. The first ordinary read in a transaction
 * establishes a snapshot, and every later ordinary read in that transaction is
 * served from it — including one that runs after a lockForUpdate() on some
 * other table. So an Action that reads anything before taking the batch mutex,
 * and then asks this question with an ordinary SELECT, gets an answer from
 * BEFORE the mutex was held. An assignment revoked in that window is invisible,
 * and the write proceeds on authorization that is already false. This is the
 * exact shape that let two tills double-collect on a charge balance — see
 * EnrollmentUpdateRule and ChargeBalance for the two other places this
 * reasoning is load-bearing.
 *
 * A locking read is not subject to the snapshot: it sees the latest committed
 * rows and holds them until the transaction ends. So the write path passes
 * locking: true and treats that answer as binding, while a future UI-rendering
 * caller would pass the default and get a cheap, consistent-enough read.
 *
 * DATABASE-BACKED, NEVER A LOADED RELATION
 * ----------------------------------------
 * This deliberately does not read $batch->instructors(). A loaded relationship
 * is a mutable property: it can be stale, and it can be replaced outright by
 * any code holding the model — `$batch->setRelation('instructors', collect([$me]))`
 * is authorization forged from the caller's own memory.
 *
 * STAFF MUST NOT HOLD complete_enrollment (P1-T11's lesson, restated)
 * ---------------------------------------------------------------------
 * Holding the unrestricted permission satisfies step 1 above and step 3 never
 * runs, so a role accidentally granted both would sail through every scoped
 * test that used it. RolePermissionSeeder deliberately grants staff only
 * `complete_assigned_batch_enrollment`, and CompletionAuthorizationTest proves
 * the scoping with a BESPOKE role holding only that ability — the seeded role
 * cannot expose this gap, because it happens to be correctly configured today;
 * only a role built to hold nothing else can prove the rule's own branching is
 * correct independent of what the seeder decided.
 */
final class CompletionRule
{
    public function allows(User $actor, int $batchId, bool $locking = false): bool
    {
        if ($actor->can('complete_enrollment')) {
            return true;
        }

        if (! $actor->can('complete_assigned_batch_enrollment')) {
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
     * hydration.
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
