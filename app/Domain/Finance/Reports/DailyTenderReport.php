<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use App\Support\CentreCalendar;
use Illuminate\Support\Collection;

/**
 * One local calendar day of standing tender totals — live, never a persisted
 * reconciliation.
 *
 * A DAY IS A PERIOD, SO THIS IS TenderBreakdownReport CALLED WITH A NARROWER ONE
 * -----------------------------------------------------------------------------
 * The grouping — by tender, filtered to standing payments, summed in SQL — has
 * exactly one definition in this domain, and it lives in
 * `TenderBreakdownReport::forPeriod()`. Rewriting it here for a single day
 * would be a second definition of "totalled by tender" that could silently
 * drift from the first, so this class holds no query of its own: it converts
 * `$localDate` to a one-day `ReportPeriod` — {@see ReportPeriod::day()}, which
 * is the boundary that reads the centre's calendar (`CentreCalendar::TIMEZONE`)
 * through the timezone database rather than a fixed offset — and hands it
 * straight to `TenderBreakdownReport`.
 *
 * IT REPORTS CASH AND CARD ONLY, WHICH IS A DECISION AND NOT A SHORTHAND
 * -------------------------------------------------------------------------
 * Design §8 defines this report as "non-reversed **cash and card** tender
 * totals for a date". `TenderMethod`'s docblock requires anything that says
 * "cash and card" to DECIDE what it does with `bank_transfer` and `other`,
 * because "a report that filters to the two it knows about would drop money
 * from a total without saying so". {@see self::TILL_METHODS} is that decision
 * and carries the reasoning: this is a till figure, and a bank transfer is
 * money arriving in the centre's account evidenced outside this system, so it
 * never crosses the desk.
 *
 * The narrowing is passed to `forPeriod()` rather than written as a second
 * query here, so the reuse above is intact — there is still exactly one
 * definition of "totalled by tender".
 *
 * WHAT THE ENUM'S WARNING ACTUALLY BUYS, AND WHERE THE REST OF THE MONEY IS
 * -------------------------------------------------------------------------
 * An earlier version of this class argued the opposite at length: that §8's
 * wording was passing shorthand and that narrowing would be the silent drop
 * the enum warns against, so `forDay()` returned all four methods. The
 * cross-review rejected that against the locked design, and it was right to.
 *
 * The paragraph is recorded rather than deleted because the concern behind it
 * is real and is answered here rather than dismissed: what this report omits,
 * `TenderBreakdownReport` still shows for the same day, and
 * `DailyTenderReportTest` asserts both halves — that a `bank_transfer`
 * received on the day is excluded here, and that it is still visible there.
 * The omission is a stated rule with a test behind it. Nothing is dropped
 * without saying so; if a figure looks short here, the breakdown is where the
 * remainder is.
 *
 * WHAT THIS REPORT DELIBERATELY DOES NOT DO
 * ---------------------------------------------
 * Design §8: this is a LIVE figure, not a persisted reconciliation. There is
 * no counted-drawer workflow, no stored expected figure, and no attested
 * difference between what the drawer holds and what the system says it should
 * hold — staff confirmation at the moment a tender is recorded is the source
 * of truth, full stop. That is an explicit decision rather than a gap this
 * class happens to leave: a formal end-of-day reconciliation may be added
 * later if operational experience calls for it, and when it is, it is a
 * different feature built on top of this figure, not a change to what
 * `forDay()` answers.
 */
final class DailyTenderReport
{
    public function __construct(private readonly TenderBreakdownReport $tenderBreakdown) {}

    /**
     * The tender methods that physically cross the desk.
     *
     * Design section 8 defines this report as "non-reversed **cash and card**
     * tender totals for a date", and that is the whole list. `bank_transfer` is
     * "money arriving in the centre's account, evidenced outside this system"
     * (see `TenderMethod`), so it never passes the till and has no place in a
     * figure someone reconciles a drawer against; `other` is by definition not
     * one of the two this report names.
     *
     * `TenderMethod`'s docblock requires any report that says "cash and card"
     * to DECIDE what it does with the other two, rather than filter to the ones
     * it happens to know about. This constant is that decision, made once and
     * named — and `DailyTenderReportTest` asserts that a `bank_transfer` tender
     * received on the day is excluded, so the omission is a stated rule with a
     * test behind it rather than money dropped from a total silently.
     *
     * The payment-method breakdown is the report that shows every method; if a
     * figure looks short here, that is where the remainder is.
     *
     * @var list<TenderMethod>
     */
    private const TILL_METHODS = [TenderMethod::Cash, TenderMethod::Card];

    /**
     * The day's standing cash and card totals, on the centre's calendar.
     *
     * @return Collection<int, array{method: TenderMethod, total: Money}>
     */
    public function forDay(string $localDate): Collection
    {
        return $this->tenderBreakdown->forPeriod(ReportPeriod::day($localDate), self::TILL_METHODS);
    }
}
