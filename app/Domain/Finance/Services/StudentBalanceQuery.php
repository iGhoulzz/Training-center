<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Data\EnrollmentBalance;
use App\Domain\Finance\Data\StudentBalanceSummary;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A student's whole balance picture, published for the portal's "my balance"
 * page. Design §4.2, §11.4, §11.5.
 *
 * WHY THIS EXISTS: outstandingForEnrollment() PER ROW IS THE N+1 THIS TASK
 * REMOVES.
 * ---------------------------------------------------------------------------
 * ChargeQueryService::outstandingForEnrollment() runs roughly two queries per
 * enrolment. Calling it once per row on the page every student is likely to
 * open at once is exactly the shape design §4.2 forbids. forStudent()
 * answers every enrolment a student holds — billed or not — in a FIXED
 * number of statements, proven by StudentBalanceQueryTest's query-count
 * assertion, which counts identically for one enrolment and for twenty.
 *
 * NEVER `DB::table('enrollments')`, AND NEVER `Enrollment::query()` EITHER.
 * ---------------------------------------------------------------------------
 * `docs/ENGINEERING.md`: "Finance reads enrollment data via
 * EnrollmentQueryService, never by querying `enrollments` directly." A raw
 * `DB::table('enrollments')` is additionally refused by
 * `ActionBoundaryArchTest`'s empty allowlist, in any file under `app/` —
 * which is what that test scans (`File::allFiles(app_path())`), so `tests/`,
 * `database/` and `scripts/` are outside it. This class only
 * ever opens `DB::table('charges')` — a table Finance owns — and reaches
 * `enrollments` through `EnrollmentQueryService::scopeToStudent()`, which
 * performs the join from the Enrolment domain's own side.
 *
 * It does still NAME two `enrollments` columns — `enrollments.id` in the select
 * and in the order — which the rule permits (it forbids querying the table, not
 * referring to a column the service just joined) but which `joinCatalogueTo()`
 * avoids by publishing BATCH_ID/COURSE_ID aliases. Stated rather than claimed
 * away: an earlier version of this docblock said the class reaches `enrollments`
 * EXCLUSIVELY through the service, which those two lines contradict.
 *
 * scopeToStudent() IS A RIGHT JOIN, WHICH IS WHY AN UNBILLED ENROLMENT STILL
 * APPEARS.
 * ---------------------------------------------------------------------------
 * `joinCatalogueTo()` — PHASE 2's Task 10 revenue helper; phase 3's Task 10 is
 * export retention, and the numbers collide — is an INNER join, and
 * correctly so: an unbilled enrolment has nothing to contribute to a SUM.
 * `scopeToStudent()` is the opposite case, and is a RIGHT JOIN for exactly
 * that reason: it keeps every one of the student's `enrollments` rows and
 * NULL-pads `charges.*` where none exists, which is what lets this class
 * answer "chargeId === null, outstanding zero" for an unbilled enrolment
 * rather than silently dropping the row.
 *
 * REUSES ChargeBalance, DOES NOT RESTATE ITS ARITHMETIC.
 * ---------------------------------------------------------------------------
 * The outstanding figure is `ChargeBalance::outstandingSql()` — the
 * identical string `ChargeBalance::outstandingFor()` executes — selected
 * once per enrolment row, rather than a second subtraction written here.
 * Restating the subtraction would be the `paid_amount` mistake wearing a
 * third name: two expressions that agree today and diverge the first time a
 * write-off or an adjustment changes what counts. `ChargeBalance` does not
 * exclude a written-off charge from the outstanding figure — design §4 keeps
 * the debt in history and leaves exclusion to the aged report alone — and
 * this class inherits that by construction, since it never adds a filter
 * `ChargeBalance` itself does not apply.
 *
 * EVERY ENROLMENT, WHATEVER ITS STATUS — AND THAT IS A DECISION.
 * ---------------------------------------------------------------------------
 * No filter on `enrollments.status`, so `withdrawn` and `completed` enrolments
 * appear beside active ones. Phase 2's design settles why: "Withdrawal is not a
 * financial event. Withdrawing an enrolment leaves the bill exactly as it
 * stands." A withdrawn enrolment can therefore still be owed money, and hiding
 * it would show a student a total they cannot account for from the rows above
 * it. Completion is the same case with a happier ending.
 *
 * Recorded because the class would otherwise be silent about it and T7 renders
 * whatever comes back; StudentBalanceQueryTest pins it.
 *
 * MONEY HYDRATION: fromDecimal(), NEVER fromDirham().
 * ---------------------------------------------------------------------------
 * `OUTSTANDING_SQL` is `(charges.amount - COALESCE(SUM(...), 0))` over
 * `decimal(12,3)` columns, so MySQL returns a DECIMAL, and PDO hands that
 * back as the STRING `"123.456"` — not an integer count of dirham. money()
 * below applies the identical type guard `ChargeBalance::money()` applies
 * (`ChargeBalance::money()`): a value that is neither string nor int is a
 * thrown error rather than a silent cast. `Money::fromDirham((int) "123.456")`
 * would truncate to 123 dirham and report 0.123 LYD against a 123.456 LYD
 * debt — a wrong balance shown to a student, with no exception anywhere.
 *
 * NOTHING DERIVED IS STORED.
 * ---------------------------------------------------------------------------
 * The total is `Money::add()` folded over the per-row figures this class
 * already computed, in PHP, after the query returns — never a second SQL
 * SUM. It cannot disagree with the rows it is a total of.
 */
final class StudentBalanceQuery
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * Every enrolment a student holds, with its outstanding figure, and the
     * total across all of them — in a fixed number of statements.
     *
     * A STUDENT WITH NO ENROLMENTS GETS AN EMPTY ARRAY AND Money::zero(),
     * NOT AN EXCEPTION. Unlike ChargeBalance::outstandingFor(), which throws
     * for a charge id that matches nothing because that means the caller is
     * holding something stale, "this student has never enrolled" is an
     * ordinary state a newly-issued portal account can be in.
     */
    public function forStudent(int $studentId): StudentBalanceSummary
    {
        $rows = $this->enrollments->scopeToStudent(
            DB::table('charges'),
            'charges.enrollment_id',
            $studentId,
        )
            ->select([
                'enrollments.id as enrollment_id',
                'charges.id as charge_id',
            ])
            ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
            ->orderBy('enrollments.id')
            ->get();

        $enrollments = [];
        $total = Money::zero();

        foreach ($rows as $row) {
            $chargeId = $row->charge_id === null ? null : (int) $row->charge_id;

            /*
             * A NULL charge_id means the RIGHT JOIN found no matching charge
             * row, so OUTSTANDING_SQL evaluated `NULL - 0` — also NULL. There
             * is nothing to hydrate; the enrolment simply owes nothing yet.
             */
            $outstanding = $chargeId === null
                ? Money::zero()
                : self::money($row->{ChargeBalance::OUTSTANDING_ALIAS}, (int) $row->enrollment_id);

            $balance = new EnrollmentBalance(
                enrollmentId: (int) $row->enrollment_id,
                chargeId: $chargeId,
                outstanding: $outstanding,
            );

            $enrollments[$balance->enrollmentId] = $balance;
            $total = $total->add($outstanding);
        }

        return new StudentBalanceSummary($enrollments, $total);
    }

    /**
     * Hydrate a DECIMAL the driver handed back, refusing anything the money
     * parser cannot read exactly.
     *
     * The identical guard `ChargeBalance::money()` applies
     * (`ChargeBalance::money()`) — a value that is neither string nor int is a
     * programming error, not a value to be coerced.
     */
    private static function money(mixed $value, int $enrollmentId): Money
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "Enrolment [{$enrollmentId}]'s outstanding balance came back as something the money parser cannot read exactly."
            );
        }

        return Money::fromDecimal((string) $value);
    }
}
