<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\MonthRange;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Money paid out other than wages, in total, for whole local calendar months —
 * the source `ProfitReport` subtracts, alongside wage cost, from collected revenue.
 *
 * Spec section 8, "Profit with expenses": profit is collected revenue, minus
 * finalized wage cost, minus NON-REVERSED expenses paid in the period.
 *
 * `paid_on` IS A LOCAL DATE, NOT AN INSTANT, SO THIS CLASS TAKES NO `ReportPeriod`
 * ----------------------------------------------------------------------------
 * `payments.received_at` is a UTC instant, and `ReportPeriod::applyTo()` exists
 * to turn a local calendar boundary into the half-open UTC range that column
 * needs. `expenses.paid_on` is a `date` — the day the money left, in the centre's
 * own calendar, with no time and no zone. It is compared with bare local `Y-m-d`
 * boundaries, exactly as `WageCostReport` compares `posting_period_start`, and it
 * must never be handed to `ReportPeriod`. See `WageCostReport`'s docblock for the
 * full account of why: such a filter can land correctly in a zone ahead of UTC and
 * still rest on the sign of an offset that appears nowhere near it.
 *
 * So the methods here take a `MonthRange`, whose `firstLocalDate()` and
 * `exclusiveEndLocalDate()` are local dates already. The comparison is a range on
 * the column itself, never `MONTH()`/`YEAR()`, so its index stays usable.
 *
 * AN EXPENSE COUNTS IN THE MONTH IT WAS PAID, AND ONLY THAT MONTH
 * ---------------------------------------------------------------
 * Cash basis, like revenue: one date per expense, no incurred date and no
 * spreading. Rent paid on 1 October for the quarter counts wholly in October. An
 * expense paid on the last day of a month counts in that month; one paid on the
 * first day of the next does not.
 *
 * ONLY NON-REVERSED EXPENSES COUNT, HERE AND THEREFORE IN PROFIT
 * --------------------------------------------------------------
 * `Expense::scopeNotReversed()` is the one mechanism. A reversed expense drops out
 * of the figure of the month it was paid in, so that month's profit moves — through
 * a recorded, attributed reversal and no other way. This is the behaviour a
 * reversed payment already has on revenue.
 *
 * THE SUM IS SQL, AND THE RESULT IS Money
 * ---------------------------------------
 * `SUM(amount)` runs inside the database, where DECIMAL sums are exact, and the one
 * scalar it produces is hydrated through `Money::fromDecimal()`. Summing
 * `decimal:3` strings in PHP converts them to float (design section 6). `SUM()`
 * over zero rows is SQL NULL rather than zero; this class never returns a null
 * figure, so a month with no expenses is exactly `0.000` — which is what makes
 * such a month's profit read as it did before expenses existed.
 */
final class ExpenseReport
{
    /**
     * Non-reversed expenses paid in the local calendar month.
     *
     * @throws InvalidArgumentException if $month is not 1-12.
     */
    public function totalForMonth(int $year, int $month): Money
    {
        return $this->totalForMonths(MonthRange::single($year, $month));
    }

    /** Non-reversed expenses paid within whole local calendar months. */
    public function totalForMonths(MonthRange $range): Money
    {
        $row = $this->paidInRange($range)->selectRaw('SUM(amount) as total')->first();
        $value = $row?->total;

        return Money::fromDecimal($value === null ? '0' : (string) $value);
    }

    /**
     * Every non-reversed expense paid inside $range and nothing selected yet.
     *
     * Starts on the Eloquent builder to reach the model's local scope and drops to
     * the query builder with `toBase()` immediately after, the conversion
     * `WageCostReport::finalizedLinesInRange()` makes for the same reason.
     */
    private function paidInRange(MonthRange $range): Builder
    {
        return Expense::query()
            ->notReversed()
            ->where('paid_on', '>=', $range->firstLocalDate())
            ->where('paid_on', '<', $range->exclusiveEndLocalDate())
            ->toBase();
    }
}
