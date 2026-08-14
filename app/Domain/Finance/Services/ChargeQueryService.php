<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What Enrolment is allowed to know about a bill.
 *
 * THE REVERSE DIRECTION OF EnrollmentQueryService, AND READ-ONLY.
 * --------------------------------------------------------------
 * Design §12 names this as the interface Finance publishes for Enrolment, so
 * that neither domain queries the other's models directly. §11 adds the part
 * that matters: **a query service that also deletes is a write path wearing a
 * reader's name.** Deleting a bill alongside its enrolment goes through
 * DeleteUncommittedChargeAction, which takes the lock and owns the refusals;
 * nothing here writes anything.
 *
 * IT ANSWERS WITH VALUES, NOT WITH CHARGES.
 * Handing back a Charge model would let Enrolment code walk into Finance and
 * would make the boundary unenforceable — the same reasoning
 * EnrollmentQueryService applies in the other direction.
 *
 * THE BALANCE COMES FROM ChargeBalance, NOT FROM A SECOND SUM.
 * ChargeBalance is the single definition of outstanding, in SQL and in PHP
 * (design §§4, 6, 11). This class locates the charge; it does not compute
 * money.
 */
final class ChargeQueryService
{
    /** Whether this enrolment has a bill at all. */
    public function existsForEnrollment(int $enrollmentId): bool
    {
        return DB::table('charges')->where('enrollment_id', $enrollmentId)->exists();
    }

    /**
     * The `CHG-` reference of an enrolment's bill, or null if it has none.
     *
     * Nullable rather than throwing: phase 1 enrolments predate billing, and a
     * caller asking "what is this enrolment's bill reference" is entitled to the
     * answer "it has none" without handling an exception for the ordinary case.
     */
    public function referenceForEnrollment(int $enrollmentId): ?string
    {
        $reference = DB::table('charges')
            ->where('enrollment_id', $enrollmentId)
            ->value('reference');

        return $reference === null ? null : (string) $reference;
    }

    /**
     * What an enrolment's bill still owes, or null if it has no bill.
     *
     * Read this inside the transaction holding the charge's lock when the answer
     * is about to be acted on — ChargeBalance does not take the lock and does not
     * pretend to.
     */
    public function outstandingForEnrollment(int $enrollmentId): ?Money
    {
        $chargeId = DB::table('charges')
            ->where('enrollment_id', $enrollmentId)
            ->value('id');

        return $chargeId === null
            ? null
            : ChargeBalance::outstandingFor((int) $chargeId);
    }
}
