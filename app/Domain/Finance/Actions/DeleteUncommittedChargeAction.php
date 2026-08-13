<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\ChargeAlreadyCommittedException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Staff\Support\ActivityEvent;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Remove a bill that nothing has happened to, so its enrolment can go with it.
 *
 * INTERNAL. THE ONLY CALLER IS DeleteEnrollmentAction.
 * ----------------------------------------------------
 * It takes no actor and consults no policy, and design §4 says why in as many
 * words: `ChargePolicy::delete()` answers "may this actor delete a bill on its
 * own", and that answer is **no, for everyone, unconditionally**. This is not
 * that act. It is the financial half of deleting an enrolment, whose
 * entitlement — `delete_enrollment` — DeleteEnrollmentAction has already
 * checked against the enrolment row it holds locked.
 *
 * Design §12 requires that a student recorded in error stays removable, because
 * otherwise "the mistake is permanent". Every enrolment now carries a charge and
 * financial foreign keys restrict on delete, so without this the first erroneous
 * enrolment of phase 2 would be undeletable.
 *
 * EVERY DISQUALIFYING CONDITION IS READ UNDER THE CHARGE'S OWN LOCK
 * ----------------------------------------------------------------
 * `lockForUpdate()` is taken before any of the three checks, inside the caller's
 * transaction — the shape EnrollStudentAction uses for everything its decision
 * reads. A payment recorded between an unlocked read and this delete would
 * otherwise commit against a bill that no longer exists.
 *
 * THREE CONDITIONS, AND WHY THE THIRD IS A LOG QUERY
 * --------------------------------------------------
 * An allocation is a row. A write-off is a column. An **adjustment has neither**
 * — design §4 deliberately gives `charges` no adjustment columns, because the
 * append-only activity log *is* that audit record. So the only place an
 * adjustment exists is the log, and reading it here is not a shortcut around a
 * missing column; it is reading the record the design nominated.
 *
 * `AdjustChargeAction` is the sole writer of that shape: an `updated` entry
 * carrying a `reason` property. A write-off's automatic entry also has event
 * `updated`, which is why the check is on the property rather than the event —
 * and a write-off is refused by its own column check anyway, so the two do not
 * have to be told apart to be safe. They are told apart so the refusal names
 * the true cause.
 */
final class DeleteUncommittedChargeAction
{
    /**
     * @throws ChargeAlreadyCommittedException if money or a human decision is
     *                                         attached to this bill.
     */
    public function execute(int $enrollmentId): void
    {
        DB::transaction(function () use ($enrollmentId): void {
            $charge = Charge::query()
                ->lockForUpdate()
                ->where('enrollment_id', $enrollmentId)
                ->first();

            /*
             * No charge is not an error. Phase 1 enrolments predate billing, and
             * a test may build an enrolment directly through its factory. The
             * enrolment's own deletion is the caller's business either way.
             */
            if (! $charge instanceof Charge) {
                return;
            }

            $chargeId = (int) $charge->getKey();

            if ($charge->allocations()->exists()) {
                throw ChargeAlreadyCommittedException::allocated($chargeId);
            }

            if ($charge->isWrittenOff()) {
                throw ChargeAlreadyCommittedException::writtenOff($chargeId);
            }

            if ($this->hasBeenAdjusted($chargeId)) {
                throw ChargeAlreadyCommittedException::adjusted($chargeId);
            }

            $charge->delete();
        });
    }

    /**
     * Whether the activity log records a correction to this charge's amount.
     *
     * Scoped to the charge as a subject, to `updated`, and to entries carrying a
     * `reason` property — the entry `AdjustChargeAction` builds by hand for
     * exactly this purpose, because a diff produced from a bare model event has
     * nowhere to hang the caller's reason.
     */
    private function hasBeenAdjusted(int $chargeId): bool
    {
        return Activity::query()
            ->where('subject_type', Charge::class)
            ->where('subject_id', $chargeId)
            ->where('event', ActivityEvent::UPDATED)
            ->whereNotNull('properties->reason')
            ->exists();
    }
}
