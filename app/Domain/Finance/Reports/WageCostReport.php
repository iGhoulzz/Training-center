<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Wage cost per person and in total, from finalized payroll lines of all
 * three run types — the source `ProfitReport` subtracts from collected
 * revenue.
 *
 * Design §8: "Wage cost per period | Per person and in total, from finalized
 * payroll lines of all three run types."
 *
 * THE TRAP THIS CLASS EXISTS TO AVOID: `posting_period_start` IS A LOCAL
 * DATE, NOT AN INSTANT, AND `ReportPeriod` IS BUILT FOR INSTANTS
 * ------------------------------------------------------------------------
 * `payments.received_at`, which `RevenueReport` filters, is a `timestamp` — a
 * UTC instant, and `ReportPeriod::applyTo()` exists specifically to convert a
 * local calendar boundary into the half-open UTC range that column needs.
 * `payroll_lines.posting_period_start` is a `date` — a local calendar date
 * with no time and no zone, already reduced to the first of its month by
 * `FinalizePayrollRunAction` (a salary line's own segment month, or, for a
 * correction, the month copied from the line it corrects). Handing that
 * column to `ReportPeriod::applyTo()` would compare a `date` against
 * `startsAt`/`endsAt` UTC datetimes, and MySQL widens the `date` to naive
 * midnight to do it.
 *
 * AN EARLIER VERSION OF THIS PARAGRAPH CLAIMED THAT MISCOUNTS FEBRUARY INTO
 * MARCH. IT DOES NOT, AND THE CORRECTION IS THE POINT. Measured against this
 * project's own MySQL with the bindings `ReportPeriod::month(2026, 3)`
 * actually produces — `>= '2026-02-28 22:00:00'` and `< '2026-03-31 22:00:00'`
 * — a `posting_period_start` of `2026-02-28` is **excluded**, `2026-03-01` is
 * selected, and `2026-04-01` is excluded. At a positive UTC offset the range
 * happens to land correctly on a column normalised to the first of the month.
 *
 * The real hazard is that it works **only** because Africa/Tripoli is ahead of
 * UTC, and nothing in this code says so. At a negative offset the month's
 * start instant falls *after* its own local midnight — for UTC-5, March would
 * begin at `2026-03-01 05:00:00` while the column reads `2026-03-01 00:00:00`
 * — and every row for the month drops out of its own report. A filter whose
 * correctness rests on the sign of an offset that appears nowhere near it is
 * the kind that survives review and fails on relocation, so this class does
 * not build one.
 *
 * So this class does not take a `ReportPeriod` at all. `forMonth()` and
 * `totalForMonth()` take the local calendar month directly, and every filter
 * below is a plain equality against `posting_period_start` — never a range,
 * and never `MONTH()`/`YEAR()`, either of which would also make the column's
 * own index unusable. Equality against `'2026-03-01'` is the honest
 * comparison for a column that is already normalised to the first of the
 * month.
 *
 * ALL THREE RUN TYPES CONTRIBUTE, WITH NO FURTHER FILTER BY TYPE
 * ------------------------------------------------------------------
 * A monthly-salary line, an instructor line and an adjustment (correction)
 * line all carry `finalized_at` and `posting_period_start` the same way once
 * finalized, and design §8 requires all three counted as cost. Nothing here
 * reads `payroll_runs.type` — the two columns this class filters already
 * select the right set regardless of which of the three shapes a line is.
 * Excluding a correction is exactly how a March fix filed in June would go
 * missing from March's figure.
 *
 * ONLY FINALIZED LINES ARE A COST
 * ----------------------------------
 * `PayrollLine::scopeFinalized()` — `finalized_at IS NOT NULL` — is used
 * wherever the query starts from the model, per that scope's own docblock.
 * `adjustmentsInPeriod()` is the exception and spells the condition out by
 * hand, because it starts from `DB::table()` and cannot reach a local scope;
 * that filter is in fact redundant there, since a draft line's
 * `posting_period_start` is NULL by CHECK and the period equality already
 * excludes it. Both halves are stated because a scope that later grows a
 * second condition would be followed by one and not the other. Cost is
 * incurred at
 * finalization, not at draft. A draft is provisional and is not spent yet.
 *
 * DRAFT-TIME ADJUSTMENTS ARE A SECOND, SIGNED SOURCE OF COST — SUMMED
 * SEPARATELY IN SQL AND COMBINED AS Money, NEVER JOINED INTO THE SAME
 * AGGREGATE AS `computed_amount`
 * ------------------------------------------------------------------------
 * `payroll_line_adjustments.amount` (signed: bonuses positive, deductions
 * negative) is a bonus or deduction decided while the line's run was still a
 * draft, and design §8 counts it as part of that person's wage cost the same
 * as the line's own `computed_amount`. A line can carry more than one
 * adjustment, which is exactly the trap: an inner join from `payroll_lines`
 * to `payroll_line_adjustments` multiplies each matching line into one row
 * per adjustment, and `SUM(computed_amount)` over that joined result would
 * count the line's own amount once per adjustment row rather than once — a
 * line of `1000.000` with two `+10.000` adjustments would report `2020.000`
 * instead of `1020.000`. This class never runs that query.
 * `finalizedLinesInPeriod()` sums `computed_amount` alone;
 * `adjustmentsInPeriod()` sums `payroll_line_adjustments.amount` alone — two
 * independent `SUM()` aggregates, each exact because each scans a query
 * where the row being summed does not repeat — and the two totals are
 * combined with `Money::add()`, which is safe integer arithmetic performed
 * once on two values SQL has already summed, not a second summation of raw
 * rows in PHP.
 *
 * A CORRECTION'S OWN POSTING PERIOD IS WHAT MAKES A JUNE FIX LAND IN MARCH
 * ----------------------------------------------------------------------------
 * `FinalizePayrollRunAction` copies a correction line's `posting_period_start`
 * from the line it corrects, not from the date the adjustment run itself was
 * finalized on. That mechanism — not anything in this class — is what makes
 * `forMonth(2026, 3)` include a correction finalized in June: this report
 * only ever reads the column that mechanism writes, it never derives which
 * month a line belongs to.
 */
final class WageCostReport
{
    /**
     * Wage cost per person for the month, one row per user who has any
     * finalized cost in it.
     *
     * @return Collection<int, array{user_id: int, total: Money}>
     *
     * @throws InvalidArgumentException if $month is not 1-12.
     */
    public function forMonth(int $year, int $month): Collection
    {
        $period = $this->localMonthStart($year, $month);

        $lineTotals = $this->groupedTotals(
            $this->finalizedLinesInPeriod($period),
            'user_id',
            'SUM(computed_amount)',
        );

        $adjustmentTotals = $this->groupedTotals(
            $this->adjustmentsInPeriod($period),
            'payroll_lines.user_id',
            'SUM(payroll_line_adjustments.amount)',
        );

        $userIds = $lineTotals->keys()->merge($adjustmentTotals->keys())->unique()->sort()->values();

        return $userIds->map(fn (int $userId): array => [
            'user_id' => $userId,
            'total' => $lineTotals->get($userId, Money::zero())
                ->add($adjustmentTotals->get($userId, Money::zero())),
        ])->values();
    }

    /**
     * The total wage cost across every person for the month — the figure
     * `ProfitReport` subtracts from revenue.
     *
     * @throws InvalidArgumentException if $month is not 1-12.
     */
    public function totalForMonth(int $year, int $month): Money
    {
        $period = $this->localMonthStart($year, $month);

        $lineTotal = $this->scalarTotal($this->finalizedLinesInPeriod($period), 'SUM(computed_amount)');
        $adjustmentTotal = $this->scalarTotal(
            $this->adjustmentsInPeriod($period),
            'SUM(payroll_line_adjustments.amount)',
        );

        return $lineTotal->add($adjustmentTotal);
    }

    /**
     * Finalized payroll lines posting to $period, of any of the three run
     * shapes — every row this report needs and nothing selected yet.
     *
     * `PayrollLine::scopeFinalized()` is an Eloquent local scope, so this
     * starts on the Eloquent builder to reach it and drops to the query
     * builder with `toBase()` immediately after — the same conversion
     * `ReportPeriod::applyTo()`'s own docblock describes for a caller that
     * starts from a model.
     */
    private function finalizedLinesInPeriod(string $period): Builder
    {
        return PayrollLine::query()
            ->finalized()
            ->where('posting_period_start', $period)
            ->toBase();
    }

    /**
     * Draft-time adjustments belonging to a finalized line posting to
     * $period — joined only to reach `finalized_at` and
     * `posting_period_start`, both of which live on the line, never to sum
     * `computed_amount` alongside them. See the class docblock for why
     * keeping this query separate from {@see finalizedLinesInPeriod()} is
     * the whole point.
     */
    private function adjustmentsInPeriod(string $period): Builder
    {
        return DB::table('payroll_line_adjustments')
            ->join('payroll_lines', 'payroll_lines.id', '=', 'payroll_line_adjustments.payroll_line_id')
            ->whereNotNull('payroll_lines.finalized_at')
            ->where('payroll_lines.posting_period_start', $period);
    }

    /**
     * $rawSum, grouped by $groupByColumn, as Money keyed by user id.
     *
     * The aggregation happens entirely in SQL — `SUM()` runs inside the
     * database, and the one scalar each group produces is the only thing
     * hydrated, through `Money::fromDecimal()`, matching the pattern
     * `RevenueReport::totalsGroupedBy()` uses for the same reason (design
     * §6: summing `decimal:3` strings in PHP converts them to float).
     *
     * @return Collection<int, Money> keyed by user id
     */
    private function groupedTotals(Builder $query, string $groupByColumn, string $rawSum): Collection
    {
        return $query
            ->selectRaw("{$groupByColumn} as user_id, {$rawSum} as total")
            ->groupBy($groupByColumn)
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->user_id)
            ->map(fn (object $row): Money => Money::fromDecimal((string) $row->total));
    }

    /**
     * $rawSum over the whole (ungrouped) query, as one Money — `0.000` when
     * nothing matches, since `SUM()` over zero rows is SQL NULL rather than
     * zero and this class never returns a null cost.
     */
    private function scalarTotal(Builder $query, string $rawSum): Money
    {
        $row = $query->selectRaw("{$rawSum} as total")->first();
        $value = $row?->total;

        return Money::fromDecimal($value === null ? '0' : (string) $value);
    }

    /**
     * The first day of the local calendar month, as the bare `Y-m-01` string
     * `posting_period_start` itself already normalises to.
     *
     * Deliberately never built through `CarbonImmutable`/`CentreCalendar`:
     * nothing here converts a local wall-clock moment to a UTC instant, so
     * there is no timezone to consult. See the class docblock — this column
     * is a calendar fact, not a moment, and treating it as one is the entire
     * trap this class exists to avoid.
     *
     * @throws InvalidArgumentException if $month is not 1-12.
     */
    private function localMonthStart(int $year, int $month): string
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Not a calendar month: [{$month}]. Expected 1-12.");
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }
}
