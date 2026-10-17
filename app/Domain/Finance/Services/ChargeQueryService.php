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
    /**
     * The alias the Charges table selects "awaiting its first payment" into.
     *
     * Named here rather than spelled out at both ends, following
     * ChargeBalance::OUTSTANDING_ALIAS: a typo against the alias the query
     * selected reads as "never awaiting", which hides a button rather than
     * failing.
     */
    public const AWAITING_FIRST_PAYMENT_ALIAS = 'awaiting_first_payment';

    /** Whether this enrolment has a bill at all. */
    public function existsForEnrollment(int $enrollmentId): bool
    {
        return DB::table('charges')->where('enrollment_id', $enrollmentId)->exists();
    }

    /**
     * The one rule for a bill that is AWAITING ITS FIRST PAYMENT, as a SQL
     * condition over `charges` — for `selectRaw` (the Charges table's button) and
     * for `whereRaw` (the Enrol & Collect page's server-side check) alike.
     *
     * A bill is awaiting its first payment when ALL of these hold:
     *
     *   - no allocation stands against it, i.e. none from a payment that is not
     *     reversed;
     *   - it is not written off;
     *   - something is outstanding.
     *
     * **A bill whose only payment was reversed is awaiting its first payment
     * again.** That is a lead decision, flagged for the owner in the pull request,
     * and this is the one place to change it: both the button and the page read
     * this string.
     *
     * WHY IT LIVES HERE AND IS A SQL STRING, NOT A PHP FUNCTION OF A CHARGE. The
     * table decides per row, and a PHP check would be a query per row. So the
     * condition is selected into the list query once, and the page asks the
     * database the identical question about one id. The same shape, and the same
     * reasoning, as ChargeBalance::outstandingSql().
     *
     * NO SECOND REVERSAL RULE. "No allocation from a payment that is not
     * reversed" is read off ChargeBalance::allocatedSql() being zero — sound
     * because `payment_allocations.amount` has CHECK (amount > 0), so a live
     * allocation always adds to the sum — and "outstanding" is
     * ChargeBalance::outstandingSql(). The `reversed_at IS NULL` stays written
     * exactly once, in ChargeBalance.
     *
     * The fragment names `charges` unqualified, like ChargeBalance's, so it
     * composes into any query over that table.
     */
    public function awaitingFirstPaymentSql(): string
    {
        return '(charges.written_off_at IS NULL AND '
            .ChargeBalance::allocatedSql().' = 0 AND '
            .ChargeBalance::outstandingSql().' > 0)';
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
