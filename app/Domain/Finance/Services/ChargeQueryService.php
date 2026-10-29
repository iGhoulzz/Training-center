<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
     * The alias the Charges table selects "collectable" into.
     *
     * Named here rather than spelled out at both ends, following
     * ChargeBalance::OUTSTANDING_ALIAS: a typo against the alias the query
     * selected reads as "never collectable", which hides a button rather than
     * failing.
     */
    public const COLLECTABLE_ALIAS = 'collectable';

    /** Whether this enrolment has a bill at all. */
    public function existsForEnrollment(int $enrollmentId): bool
    {
        return DB::table('charges')->where('enrollment_id', $enrollmentId)->exists();
    }

    /**
     * The one rule for a bill that can take a payment through the collection
     * panel, as a SQL condition over `charges` — for `selectRaw` (the Charges
     * table's button) and for `whereRaw` (the Enrol & Collect page's server-side
     * check) alike.
     *
     * A bill is collectable when BOTH of these hold:
     *
     *   - it is not written off;
     *   - something is outstanding.
     *
     * **A payment already on the bill does not block it** (owner, 2026-10-29,
     * P4-T36): a student who paid 300 of an 850 bill is collected from again for
     * the remaining 550, and the first payment's allocation simply reduces what is
     * outstanding. Earlier revisions (P4-T32) also required that no allocation
     * stand against the bill, which made "Collect payment" a first-payment-only
     * door; that third condition is the one that went. A bill whose only payment
     * was reversed is collectable on the same two conditions, since the reversed
     * allocation no longer counts toward what was paid.
     *
     * RecordPaymentAction::isCollectableUnderLock() asks the same two questions
     * with locking reads at submit time; change both, and
     * CollectFirstPaymentOnlyTest's parity dataset will say if they disagree.
     *
     * WHY IT LIVES HERE AND IS A SQL STRING, NOT A PHP FUNCTION OF A CHARGE. The
     * table decides per row, and a PHP check would be a query per row. So the
     * condition is selected into the list query once, and the page asks the
     * database the identical question about one id. The same shape, and the same
     * reasoning, as ChargeBalance::outstandingSql().
     *
     * NO SECOND REVERSAL RULE. "Outstanding" is ChargeBalance::outstandingSql(),
     * which is where the `reversed_at IS NULL` is written, exactly once.
     *
     * The fragment names `charges` unqualified, like ChargeBalance's, so it
     * composes into any query over that table.
     */
    public function collectableSql(): string
    {
        return '(charges.written_off_at IS NULL AND '
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

    /**
     * Select each enrolment's OWN outstanding onto a query over enrolments, as
     * ChargeBalance::OUTSTANDING_ALIAS.
     *
     * ONE CORRELATED SUBSELECT, NOT ONE QUERY PER ROW. The bill is found by
     * `charges.enrollment_id = enrollments.id` — a unique index, so at most one
     * row — and its outstanding is ChargeBalance::outstandingSql(), the string
     * every other balance surface runs, so a reversed payment drops out and a
     * written-off bill still counts exactly as StudentBalanceQuery counts them
     * and the per-enrolment figures add up to the student's.
     *
     * COALESCE to zero, because an enrolment with no bill owes nothing and
     * StudentBalanceQuery::forStudent() says the same; a NULL here would print
     * as a blank cell beside rows that print 0.000.
     *
     * The caller's query is returned, so it chains. The enrolment table is only
     * ever named through the builder's own qualifyColumn(), and never queried.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $enrollments
     * @return Builder<TModel>
     */
    public function withEnrollmentOutstanding(Builder $enrollments): Builder
    {
        $bill = DB::table('charges')
            ->selectRaw(ChargeBalance::outstandingSql())
            ->whereColumn('charges.enrollment_id', $enrollments->qualifyColumn('id'));

        $outstanding = DB::query()->selectRaw('COALESCE(('.$bill->toSql().'), 0)', $bill->getBindings());

        return $enrollments->addSelect([ChargeBalance::OUTSTANDING_ALIAS => $outstanding])
            ->withCasts([ChargeBalance::OUTSTANDING_ALIAS => 'decimal:3']);
    }
}
