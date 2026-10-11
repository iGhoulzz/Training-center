<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\MonthRange;
use App\Domain\Finance\Support\ReportPeriod;

/**
 * Collected revenue, minus finalized wage cost, minus non-reversed expenses paid
 * in the period, for whole local calendar months.
 *
 * Design §8 (amended 2026-10-08, P4-T01, "Profit with expenses"): "Profit =
 * collected revenue − finalized wage cost − non-reversed expenses paid in the
 * period." That supersedes the phase 2 definition, "collected revenue minus
 * finalized wage cost".
 *
 * WHAT CHANGED, AND WHAT DID NOT
 * ------------------------------
 * The definition gained one term. For a month with no expense rows that term is
 * `0.000`, so such a month's profit is exactly what this report returned before
 * expenses existed. A month WITH expenses is a different figure by definition, and
 * the report states the definition where it is read. A reversed expense is
 * excluded, so its original month's profit moves, traceably, through the reversal.
 *
 * THE PUBLIC METHODS TAKE LOCAL CALENDAR VALUES, NEVER A `ReportPeriod`,
 * BECAUSE THE TWO HALVES ALIGN THEMSELVES BY CONSTRUCTION
 * ------------------------------------------------------------------------
 * Revenue and wage cost are read off two columns of different kinds — see
 * `WageCostReport`'s own docblock for the full account. `payments.received_at`
 * is a UTC instant `RevenueReport` filters through `ReportPeriod::applyTo()`;
 * `payroll_lines.posting_period_start` is a local calendar date
 * `WageCostReport` filters by plain equality and must never reach
 * `ReportPeriod` at all, and `expenses.paid_on` is a local calendar date
 * `ExpenseReport` filters the same way. If this class accepted a `ReportPeriod` and handed
 * it to both sides, the wage-cost half would either have to re-derive a
 * local calendar month from `ReportPeriod`'s UTC instants (re-deriving
 * exactly the value this class already has for free) or a caller would have
 * to pass the month twice, once as a `ReportPeriod` and once as raw
 * integers, and trust that both stayed in step. `forMonth()` delegates to
 * `MonthRange::single()`, while `forMonths()` builds the one UTC period its
 * revenue half needs from the range's local endpoints and gives that same
 * range to wage cost and to expenses. The three sides therefore describe the
 * same months **by construction** — there is no seam where a caller could pass
 * different boundaries to one side and the other.
 *
 * BOTH FIGURES ARE COMPOSED FROM THE EXISTING REPORTS, NEVER RE-DERIVED
 * ------------------------------------------------------------------------
 * This class runs no SQL of its own. Revenue is
 * `RevenueReport::byCourse()`'s per-course totals for the month, added
 * together with `Money::add()` — every course rolled into one figure, the
 * same "collected, cash basis, non-reversed" total `RevenueReport`'s own
 * docblock defines and asserts, read through its published grouping rather
 * than reconstructed from `payment_allocations` a second time. Wage cost is
 * `WageCostReport::totalForMonths()`, unmodified. Expenses are
 * `ExpenseReport::totalForMonths()`, unmodified. Injecting the reports
 * through the constructor — the house pattern for composing existing
 * reports rather than re-querying — is what guarantees this class and
 * `WageCostReport` or `ExpenseReport` can never quietly disagree about what
 * a figure was for any selected range: there is only one method that
 * computes each.
 *
 * PROFIT MAY BE NEGATIVE, AND IS NEVER CLAMPED
 * ------------------------------------------------
 * A month with payroll finalized and nothing collected is a real loss, and
 * `Money::subtract()` returns it signed — `isNegative()` is how a caller
 * asks. Clamping it to zero would hide exactly the month a report like this
 * exists to surface.
 */
final class ProfitReport
{
    public function __construct(
        private readonly RevenueReport $revenue,
        private readonly WageCostReport $wageCost,
        private readonly ExpenseReport $expenses,
    ) {}

    /**
     * Revenue, wage cost, expenses and profit for the local calendar month, all Money.
     *
     * @return array{revenue: Money, wageCost: Money, expenses: Money, profit: Money}
     */
    public function forMonth(int $year, int $month): array
    {
        return $this->forMonths(MonthRange::single($year, $month));
    }

    /**
     * Revenue, wage cost, expenses and profit over whole local calendar months, all Money.
     *
     * @return array{revenue: Money, wageCost: Money, expenses: Money, profit: Money}
     */
    public function forMonths(MonthRange $range): array
    {
        $revenue = $this->collectedRevenueFor(
            ReportPeriod::between($range->firstLocalDate(), $range->lastLocalDate()),
        );
        $wageCost = $this->wageCost->totalForMonths($range);
        $expenses = $this->expenses->totalForMonths($range);

        return [
            'revenue' => $revenue,
            'wageCost' => $wageCost,
            'expenses' => $expenses,
            'profit' => $revenue->subtract($wageCost)->subtract($expenses),
        ];
    }

    /**
     * `RevenueReport::byCourse()`'s per-course totals for the period, rolled
     * into one figure with `Money::add()` — combining values SQL has
     * already summed, not summing raw rows in PHP.
     */
    private function collectedRevenueFor(ReportPeriod $period): Money
    {
        return $this->revenue->byCourse($period)
            ->reduce(fn (Money $carry, array $row): Money => $carry->add($row['total']), Money::zero());
    }
}
