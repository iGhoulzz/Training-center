<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How money arrived for a period, totalled by tender — never by payment.
 *
 * WHY BY TENDER, AND WHAT "NEVER BY PAYMENT" ACTUALLY MEANS
 * -------------------------------------------------------------
 * Design §8 is explicit that this report groups by tender. `payment_tenders`
 * replaced the original design's single `payments.method` precisely because a
 * counter transaction can be split — 300 on card, 700 in cash, one payment, one
 * receipt, two `payment_tenders` rows. Grouping by payment instead would face
 * an impossible choice for that row: report one method and silently drop the
 * other's money, or merge both amounts under a method that only described half
 * of them. Grouping by `payment_tenders.method` sidesteps the choice entirely —
 * each tender contributes its own amount to its own method, so a split payment
 * contributes to two rows here, each correct on its own.
 *
 * EVERY METHOD PRESENT IS REPORTED, INCLUDING THE TWO §8's PROSE DOES NOT NAME
 * -------------------------------------------------------------------------------
 * `TenderMethod` has four cases. Design §8 and the `payment_tenders` migration
 * both speak only of cash and card in passing, but `bank_transfer` and `other`
 * are real, storable values with no narrower validation than the two named
 * ones — see `TenderMethod`'s own docblock. Filtering this report to "the two
 * it knows about" would drop money from a total without saying so, which is
 * worse than an unfamiliar row: a missing method reads as "there was none,"
 * not as "this report chose not to look." So `forPeriod()` groups on the raw
 * column and returns one row per method that has at least one standing tender
 * in the period — all four cases, whichever are actually present.
 *
 * `payments.reversed_at IS NULL` IS MANDATORY, AND IS ASSERTED HERE RATHER
 * THAN BORROWED
 * ---------------------------------------------------------------------------
 * Design §5: only tenders on a non-reversed payment count as money the centre
 * has actually collected. `payments` is joined for exactly two things — this
 * filter, and the period comparison against `received_at` — and neither is
 * shared code with `RevenueReport` or `ChargeBalance`; each report asserts the
 * reversal rule for itself, per design §5.
 *
 * AGGREGATION HAPPENS IN SQL
 * ---------------------------
 * Design §6: MySQL's DECIMAL sums are exact, while summing `decimal:3` strings
 * in PHP converts them to float and loses the dirham. `SUM(payment_tenders.amount)`
 * runs inside the database, grouped by method; the one scalar each group
 * produces is hydrated through `Money::fromDecimal()` and nothing here sums
 * ungrouped rows in PHP.
 *
 * `DailyTenderReport` reuses `forPeriod()` rather than re-implementing this
 * grouping for a single day — a day is a period, and there is exactly one
 * definition of "totalled by tender" in this domain.
 */
final class TenderBreakdownReport
{
    /**
     * Standing tender totals for the period, one row per method present.
     *
     * @return Collection<int, array{method: TenderMethod, total: Money}>
     */
    public function forPeriod(ReportPeriod $period): Collection
    {
        $query = DB::table('payment_tenders')
            ->join('payments', 'payments.id', '=', 'payment_tenders.payment_id')
            ->whereNull('payments.reversed_at')
            ->groupBy('payment_tenders.method')
            ->orderBy('payment_tenders.method')
            ->selectRaw('payment_tenders.method as method, SUM(payment_tenders.amount) as total_amount');

        $period->applyTo($query, 'payments.received_at');

        return $query->get()->map(fn (object $row): array => [
            'method' => TenderMethod::from((string) $row->method),
            'total' => Money::fromDecimal((string) $row->total_amount),
        ]);
    }
}
