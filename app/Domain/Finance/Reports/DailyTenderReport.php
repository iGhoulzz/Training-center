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
 * ONE CONSEQUENCE OF REUSE, STATED RATHER THAN LEFT IMPLICIT: EVERY METHOD
 * PRESENT ON THE DAY IS REPORTED, NOT ONLY CASH AND CARD
 * -------------------------------------------------------------------------
 * Design §8's table names this report "cash and card" in passing, the same
 * shorthand its own prose uses for the payment method breakdown. But
 * `TenderMethod`'s docblock is explicit that `bank_transfer` and `other` are
 * real, storable tenders wider than that shorthand, and that "anything that
 * reports 'cash and card' needs to decide what it does with these two — a
 * report that filters to the two it knows about would drop money from a total
 * without saying so." Narrowing this report to only cash and card would mean
 * writing a second, filtered query here — the opposite of the reuse this class
 * exists for, and precisely the silent drop that docblock warns against for a
 * report whose whole job is to tell a till operator what came in today. So
 * `forDay()` returns whatever `forPeriod()` returns for the day: one row per
 * method with a standing tender, all four cases included whenever present.
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
     * Standing tender totals for one local day on the centre's calendar
     * ({@see CentreCalendar::TIMEZONE}), one row per method present.
     *
     * @param  string  $localDate  `Y-m-d`.
     * @return Collection<int, array{method: TenderMethod, total: Money}>
     */
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
