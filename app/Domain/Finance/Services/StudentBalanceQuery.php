<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Data\EnrollmentBalance;
use App\Domain\Finance\Data\StudentBalanceSummary;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Student balances, published for the portal and staff lists.
 * Design §4.2, §11.4, §11.5.
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
 * `enrollments` through EnrollmentQueryService's scopeToStudent() and
 * joinEnrollmentStudentIdentityTo(), which perform the joins from the
 * Enrolment domain's own side.
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
 * P4-T05 adds catalogue labels through joinStudentCatalogueTo(), following the
 * preserved enrolment's non-null foreign keys. Billed and unbilled rows both
 * retain their course names and batch codes in the same statement; the page
 * selects its language from the two names rather than this query choosing one.
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
 * forStudent() folds Money::add() over its returned per-enrolment figures.
 * withOutstanding() sums that same charge-balance expression in SQL for
 * each student on a list. Both totals are derived on read; neither is stored.
 */
final class StudentBalanceQuery
{
    public const OUTSTANDING_ALIAS = 'student_outstanding_amount';

    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * Project each student's total onto an existing, paginated student query.
     * No balances are stored, and withdrawn or written-off debt still counts.
     * Enrollment owns the identity join; Finance owns the balance expression.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $students
     * @return Builder<TModel>
     */
    public function withOutstanding(Builder $students): Builder
    {
        // Keep the inner query unaggregated so MySQL can merge it and use the
        // enrolment's student index; the alias keeps ChargeBalance's one SQL
        // expression correlated to the selected charge row.
        // Keep the selected-student path on the unique enrolment lookup even
        // when optimizer estimates favour a full charge scan.
        $balances = DB::table('charges')->forceIndex('charges_enrollment_id_unique')->select('charges.id', 'charges.amount');
        $this->enrollments->joinEnrollmentStudentIdentityTo($balances, 'charges.enrollment_id');

        $total = DB::query()->fromSub($balances, 'charges')
            ->selectRaw('COALESCE(SUM('.ChargeBalance::outstandingSql().'), 0)')
            ->whereColumn(EnrollmentQueryService::ENROLLMENT_STUDENT_ID, $students->qualifyColumn('id'));

        return $students->addSelect([self::OUTSTANDING_ALIAS => $total])
            ->withCasts([self::OUTSTANDING_ALIAS => 'decimal:3']);
    }

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
        $query = $this->enrollments->scopeToStudent(
            DB::table('charges'),
            'charges.enrollment_id',
            $studentId,
        )
            ->select([
                'enrollments.id as enrollment_id',
                'charges.id as charge_id',
            ])
            ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS);

        $this->enrollments->joinStudentCatalogueTo($query);

        $rows = $query->orderBy('enrollments.id')->get();

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
                courseNameEn: (string) $row->{EnrollmentQueryService::COURSE_NAME_EN},
                courseNameAr: $row->{EnrollmentQueryService::COURSE_NAME_AR} === null
                    ? null : (string) $row->{EnrollmentQueryService::COURSE_NAME_AR},
                batchCode: (string) $row->{EnrollmentQueryService::BATCH_CODE},
            );

            $enrollments[$balance->enrollmentId] = $balance;
            $total = $total->add($outstanding);
        }

        return new StudentBalanceSummary($enrollments, $total);
    }

    /**
     * What a student owes in total, read so that a concurrent change is seen:
     * the figure an Action acts on, where forStudent() is the figure a screen
     * shows.
     *
     * WHY forStudent() IS NOT ENOUGH FOR A DECISION. It is an ordinary read, so
     * inside a transaction it is answered from the snapshot taken at the
     * transaction's first consistent read (docs/ENGINEERING.md, "A locking read
     * is the only read that is current inside a transaction"). A charge raised by
     * one thousandth, or a payment reversed, by an Action that committed after
     * that snapshot is invisible to it - and holding the student row does not
     * help, because neither AdjustChargeAction nor ReversePaymentAction takes
     * the student row. DeleteStudentAction decided on exactly that stale zero.
     *
     * WHAT IS LOCKED, IN THIS ORDER, so a caller can state its own lock order:
     *   1. the student's enrolments and their charges, in one locking read that
     *      drives from `enrollments` by the student index (a RIGHT JOIN, as
     *      forStudent() does, so an unbilled enrolment is simply a NULL charge
     *      and no charge scan is ever the driving side - a FOR UPDATE scan of
     *      `charges` would lock the table), then
     *   2. per charge, ChargeBalance::outstandingForUpdate(): the charge row
     *      again (already held) and then the allocations with the payments they
     *      join, which is what a reversal and a payment both write.
     * The caller must already hold the student row and call this inside the
     * transaction that acts on the answer: the locks go when it commits.
     *
     * NOT A SECOND FORMULA. The per-charge figure is ChargeBalance's own locking
     * form, whose pieces are the ones outstandingSql() is assembled from; the
     * total is the same fold over the same rows as forStudent(), and
     * DeleteStudentRuleTest pins the two equal, and equal to a hand-worked
     * figure, across paid, part-paid, adjusted, reversed and written-off charges.
     */
    public function totalForUpdate(int $studentId): Money
    {
        $chargeIds = $this->enrollments->scopeToStudent(
            DB::table('charges'),
            'charges.enrollment_id',
            $studentId,
        )
            ->select('charges.id as charge_id')
            ->orderBy('enrollments.id')
            ->lockForUpdate()
            ->pluck('charge_id');

        $total = Money::zero();

        foreach ($chargeIds as $chargeId) {
            // NULL: the RIGHT JOIN's unbilled enrolment, which owes nothing yet.
            if ($chargeId === null) {
                continue;
            }

            $total = $total->add(ChargeBalance::outstandingForUpdate((int) $chargeId));
        }

        return $total;
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
