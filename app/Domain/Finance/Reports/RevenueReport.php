<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collected revenue — cash basis, from allocations of payments that still stand.
 *
 * CASH BASIS, AND THE COLUMN THAT MEANS
 * --------------------------------------
 * Design §8: "A dinar is revenue in the month it arrived, not the month it was
 * billed." So the period every method here accepts is applied to
 * `payments.received_at` — the moment the money changed hands — and never to
 * `charges.due_date` or to either row's `created_at`. A charge raised in
 * February and paid in March is March's revenue, in full, and nothing here
 * reads the charge's own date to decide otherwise.
 *
 * `ReportPeriod::applyTo()` is the only thing that ever touches that
 * comparison, for the reasons its own docblock gives: a half-open range against
 * the raw column, never `BETWEEN`, never a date-extraction function that would
 * make the column's index unusable.
 *
 * A RESULT ROW NAMES AN ALLOCATION, NEVER A CHARGE OR A PAYMENT
 * ---------------------------------------------------------------
 * The source table is `payment_allocations`, joined to `payments` for the
 * period and reversal filters and to `charges` only to reach the enrolment a
 * bill was raised for — `charges.amount` itself is never read here. Revenue is
 * what was actually collected against a bill, which is the allocation, not
 * what the bill was originally for.
 *
 * `payments.reversed_at IS NULL` IS MANDATORY, AND IS ASSERTED HERE RATHER
 * THAN BORROWED
 * ---------------------------------------------------------------------------
 * Design §5: only non-reversed payments count as collected revenue. This is
 * the same rule `ChargeBalance` enforces for a bill's outstanding figure, but
 * it is not shared code with it — a report reads `payments.reversed_at`
 * directly, asserted per report rather than assumed, exactly as design §5
 * requires. `RevenueReportTest` reverses a payment and checks its allocation is
 * absent from this report specifically, and the unit's own proof obligations
 * remove this filter and confirm that test is what catches it.
 *
 * AGGREGATION HAPPENS IN SQL, AND THE RESULT IS HYDRATED ONCE
 * --------------------------------------------------------------
 * Design §6: MySQL's DECIMAL sums are exact, while summing `decimal:3` strings
 * in PHP converts them to float and loses the dirham. `SUM(payment_allocations.amount)`
 * runs inside the database; the single scalar each group produces is the only
 * thing hydrated through `Money::fromDecimal()`. Nothing here calls `->get()`
 * on the ungrouped rows and sums them in PHP.
 *
 * THE CALLER STATES ITS DIMENSION, THROUGH THE ENROLMENT BOUNDARY
 * -------------------------------------------------------------------
 * `EnrollmentQueryService::joinCatalogueTo()` is the only permitted way Finance
 * reaches batch and course, and its own docblock explains why the dimension is
 * named rather than defaulted: selecting both a batch's and a course's columns
 * unconditionally either fails under `ONLY_FULL_GROUP_BY` or silently answers
 * one row per batch when a caller wanted one row per course. `byCourse()` and
 * `byBatch()` each name exactly one dimension and group by exactly the two
 * columns that dimension selects — the id and the code — which is what lets
 * the same query run whichever way the caller asked for it.
 */
final class RevenueReport
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * Revenue for the period, grouped by course — every batch of a course
     * rolled into one row.
     *
     * @return Collection<int, array{id: int, code: string, total: Money}>
     */
    public function byCourse(ReportPeriod $period): Collection
    {
        return $this->totalsGroupedBy(
            $period,
            EnrollmentQueryService::DIMENSION_COURSE,
            EnrollmentQueryService::COURSE_ID,
            EnrollmentQueryService::COURSE_CODE,
        );
    }

    /**
     * Revenue for the period, grouped by batch.
     *
     * @return Collection<int, array{id: int, code: string, total: Money}>
     */
    public function byBatch(ReportPeriod $period): Collection
    {
        return $this->totalsGroupedBy(
            $period,
            EnrollmentQueryService::DIMENSION_BATCH,
            EnrollmentQueryService::BATCH_ID,
            EnrollmentQueryService::BATCH_CODE,
        );
    }

    /**
     * One grouped SQL aggregate, shared by both public methods and
     * parameterised only by which single catalogue dimension is selected.
     *
     * $idAlias and $codeAlias are always the pair {@see EnrollmentQueryService}
     * selected for the one dimension named in $dimensions above — grouping by
     * both is what keeps the query correct under `ONLY_FULL_GROUP_BY` without
     * relying on MySQL inferring the functional dependency itself.
     *
     * @return Collection<int, array{id: int, code: string, total: Money}>
     */
    private function totalsGroupedBy(
        ReportPeriod $period,
        string $dimension,
        string $idAlias,
        string $codeAlias,
    ): Collection {
        $query = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')
            ->whereNull('payments.reversed_at')
            ->selectRaw('SUM(payment_allocations.amount) as total_amount');

        $period->applyTo($query, 'payments.received_at');

        $this->enrollments->joinCatalogueTo($query, 'charges.enrollment_id', [$dimension]);

        $query->groupBy($idAlias, $codeAlias)->orderBy($idAlias);

        return $query->get()->map(fn (object $row): array => [
            'id' => (int) $row->{$idAlias},
            'code' => (string) $row->{$codeAlias},
            'total' => Money::fromDecimal((string) $row->total_amount),
        ]);
    }
}
