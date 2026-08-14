<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Domain\Finance\Actions\DeleteUncommittedChargeAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove an enrolment record entirely.
 *
 * SEPARATE FROM WITHDRAWAL, AND A SEPARATE GRANT.
 * -----------------------------------------------
 * Withdrawing keeps the record that the student was once on the batch, which is
 * what phase 2 bills from and what a dispute is settled with. Deleting destroys
 * it. Spec line 110 gives admins full access to enrolments, so delete_enrollment
 * is a real grant with a real path rather than a permission nothing consults —
 * but staff hold only the update grants, and withdrawal is their tool.
 *
 * SINGLE RECORD ONLY. No bulk deletion exists anywhere: Filament authorizes a
 * bulk action once against a *Any policy method and never consults the
 * per-record one, so a bulk delete could not express a per-record rule at all.
 * EnrollmentPolicy::deleteAny() is written out and refuses, which is what keeps
 * it that way — Filament resolves a MISSING policy method to Response::allow(),
 * so the refusal has to be stated rather than left implied.
 *
 * delete_enrollment is unscoped — it carries no "own batches" variant — so the
 * decision here needs no locking read of the pivot the way withdrawal does. The
 * mutex is still taken: the row being removed counts against capacity, and
 * removing it concurrently with an enrolment would decide that count from a
 * state neither saw whole.
 */
final class DeleteEnrollmentAction
{
    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly DeleteUncommittedChargeAction $deleteCharge,
    ) {}

    /**
     * @throws EnrollmentBatchChangedException if the enrolment moved batches.
     * @throws AuthorizationException if the actor may not delete enrolments.
     */
    public function execute(User $actor, Enrollment $enrollment): void
    {
        DB::transaction(function () use ($actor, $enrollment): void {
            $held = $this->mutex->acquire($enrollment);

            Gate::forUser($actor)->authorize('delete', $held->enrollment);

            /*
             * THE BILL GOES FIRST, IN THIS TRANSACTION (P2-T03, design §12).
             *
             * Every enrolment carries a charge from phase 2, and charges
             * restrict on delete — without this the first enrolment recorded in
             * error would be undeletable, and the system design's "or the
             * mistake is permanent" is precisely what P1-T11 shipped a separate
             * delete grant to avoid.
             *
             * It refuses with ChargeAlreadyCommittedException if the bill has an
             * allocation, an adjustment or a write-off, which is deliberately a
             * BUSINESS-RULE refusal rather than an authorization one: the actor
             * may delete enrolments, and this particular one has money attached.
             * The exception propagates out of the transaction, so nothing is
             * half-removed.
             *
             * It takes no actor and consults no policy on purpose — see its own
             * docblock. The entitlement was checked one line above, against the
             * row this transaction holds locked.
             */
            $this->deleteCharge->execute((int) $held->enrollment->getKey());

            $held->enrollment->delete();
        });
    }
}
