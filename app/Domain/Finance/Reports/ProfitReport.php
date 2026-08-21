<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;

/**
 * Collected revenue minus finalized wage cost, for one local calendar month.
 *
 * Design §8: "Profit | Collected revenue minus finalized wage cost for a
 * period."
 *
 * THE SIGNATURE IS `forMonth(int $year, int $month)`, NOT A `ReportPeriod`,
 * BECAUSE THE TWO HALVES ALIGN THEMSELVES BY CONSTRUCTION
 * ------------------------------------------------------------------------
 * Revenue and wage cost are read off two columns of different kinds — see
 * `WageCostReport`'s own docblock for the full account. `payments.received_at`
 * is a UTC instant `RevenueReport` filters through `ReportPeriod::applyTo()`;
 * `payroll_lines.posting_period_start` is a local calendar date
 * `WageCostReport` filters by plain equality and must never reach
 * `ReportPeriod` at all. If this class accepted a `ReportPeriod` and handed
 * it to both sides, the wage-cost half would either have to re-derive a
 * local calendar month from `ReportPeriod`'s UTC instants (re-deriving
 * exactly the value this class already has for free) or a caller would have
 * to pass the month twice, once as a `ReportPeriod` and once as raw
 * integers, and trust that both stayed in step. Taking `(int $year, int
 * $month)` once, building `ReportPeriod::month($year, $month)` for the
 * revenue half internally, and passing the same two integers straight
 * through to `WageCostReport::totalForMonth()` for the wage half makes the
 * two sides describe the same month **by construction** — there is no seam
 * where a caller could pass March to one side and February to the other.
 *
 * BOTH FIGURES ARE COMPOSED FROM THE EXISTING REPORTS, NEVER RE-DERIVED
 * ------------------------------------------------------------------------
 * This class runs no SQL of its own. Revenue is
 * `RevenueReport::byCourse()`'s per-course totals for the month, added
 * together with `Money::add()` — every course rolled into one figure, the
 * same "collected, cash basis, non-reversed" total `RevenueReport`'s own
 * docblock defines and asserts, read through its published grouping rather
 * than reconstructed from `payment_allocations` a second time. Wage cost is
 * `WageCostReport::totalForMonth()`, unmodified. Injecting both reports
 * through the constructor — the house pattern for composing existing
 * reports rather than re-querying — is what guarantees this class and
 * `WageCostReport` can never quietly disagree about what a person's wage
 * cost was for the month: there is only one method that computes it.
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
    ) {}

    /**
     * Revenue, wage cost and profit for the local calendar month, all Money.
     *
     * @return array{revenue: Money, wageCost: Money, profit: Money}
     */
    public function forMonth(int $year, int $month): array
    {
        $revenue = $this->collectedRevenueFor(ReportPeriod::month($year, $month));
        $wageCost = $this->wageCost->totalForMonth($year, $month);

        return [
            'revenue' => $revenue,
            'wageCost' => $wageCost,
            'profit' => $revenue->subtract($wageCost),
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
