<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A reporting period, stated by the caller as a **local calendar** fact and
 * held here as a **half-open UTC instant range** — the boundary every report
 * in design §8 shares.
 *
 * WHY A PERIOD IS A LOCAL FACT CONVERTED ONCE, RATHER THAN A UTC RANGE
 * ----------------------------------------------------------------------
 * "March" means March as the centre experienced it — in `Africa/Tripoli` — not
 * March on the UTC clock every `datetime` column is stored in. A payment taken
 * at 00:30 local on 1 March is still 22:30 UTC on the 28th of February to the
 * database, and a report that filtered on the UTC calendar would put it in the
 * wrong month for every user who ever looks at the number. Design §8 settles
 * that the centre's calendar wins; this class is where that conversion happens,
 * once, so no report re-derives it and no two reports can derive it differently.
 *
 * WHY THE RANGE IS HALF-OPEN, AND NEVER `BETWEEN`
 * ------------------------------------------------
 * `BETWEEN start AND end` is inclusive on both ends. A payment recorded at the
 * exact first instant of the following month would then satisfy both March's
 * range and April's, or — if `end` were instead the last instant of March —
 * satisfy neither, because "the last instant" cannot be named exactly in a
 * calendar with variable-length months and a timezone conversion in front of
 * it. `applyTo()` therefore always emits `>= start AND < end`: every instant
 * belongs to exactly one period, with no instant excluded and none double
 * counted.
 *
 * WHY THE CONVERSION READS THE TIMEZONE DATABASE AND NEVER A FIXED OFFSET
 * -------------------------------------------------------------------------
 * `CarbonImmutable`'s constructors resolve a `DateTimeZone` through PHP's own
 * timezone database (`tzdata`), which is what lets "local midnight" mean the
 * correct UTC instant even in a year Libya's offset was not what it is today —
 * 2013 ran DST, 2012 sat at a different zone entirely. A `new DateTimeZone('+02:00')`
 * would be an hour wrong for the whole of March 2013 and silently right every
 * other year in this codebase's test data, which is exactly the kind of bug a
 * fixed offset produces: correct until the one year it is not, with nothing in
 * the code to say which year that will be. Nothing here adds or subtracts an
 * hour; every conversion is `DateTimeZone(CentreCalendar::TIMEZONE)` handed to
 * Carbon, and Carbon asks the system's tz database what that means.
 *
 * WHY THIS READS `CentreCalendar::TIMEZONE` RATHER THAN NAMING THE ZONE HERE
 * -----------------------------------------------------------------------------
 * `Africa/Tripoli` is not written as a literal anywhere in this class — the
 * zone comes from `CentreCalendar::TIMEZONE`, which is where the centre's
 * calendar is decided.
 *
 * An earlier version of this sentence claimed the literal appeared in exactly
 * one place in the whole codebase. It does not: `routes/console.php` names it
 * too, a duplication `CentreCalendar`'s own docblock acknowledges. The rule
 * this class actually keeps is the narrower and checkable one — this file
 * reads the constant and never the string.
 *
 * `CentreCalendar::localise()` is deliberately not called here. It re-reads an
 * *existing* instant on the centre's calendar; this class does the opposite —
 * it builds a *new* instant from a local wall-clock description the caller
 * supplied. The two are different operations that happen to share a timezone,
 * which is exactly what the constant, rather than the method, is for.
 *
 * `CarbonImmutable` THROUGHOUT, AND THAT IS THE POINT
 * ------------------------------------------------------
 * A mutable `Carbon` whose `setTimezone()` rewrites the instance in place is a
 * bug this codebase has already shipped once (see `CentreCalendar::localise()`'s
 * own docblock). `startsAt` and `endsAt` are `CarbonImmutable`, every
 * intermediate value here is `CarbonImmutable`, and nothing is mutated after
 * construction — there is no method on this class that could rewrite a period
 * a caller is still holding.
 */
final readonly class ReportPeriod
{
    /**
     * A local calendar date, `Y-m-d`, anchored so `$` cannot match before a
     * trailing newline — the same reasoning `Money::DECIMAL_PATTERN` gives for
     * its own `D` modifier.
     *
     * This checks shape only. `checkdate()` in {@see self::parseLocalDate()}
     * is what refuses a shape-valid but calendrically impossible date such as
     * `2026-02-30`.
     */
    private const LOCAL_DATE_PATTERN = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/D';

    /**
     * Private. Every period arrives through a named constructor that has
     * already converted its local description to UTC, so there is no path to a
     * ReportPeriod holding a local instant or a non-UTC one.
     */
    private function __construct(
        /** The first instant of the period, UTC, inclusive. */
        public CarbonImmutable $startsAt,
        /** The instant immediately after the period, UTC, EXCLUSIVE. */
        public CarbonImmutable $endsAt,
    ) {}

    /**
     * The whole local calendar month.
     *
     * @throws InvalidArgumentException if $month is not 1-12.
     */
    public static function month(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(
                "Not a calendar month: [{$month}]. Expected 1-12."
            );
        }

        $start = self::localMidnight($year, $month, 1);

        // Always day 1 on both sides, so there is no month-length overflow to
        // guard against the way there is when adding a month to an arbitrary day.
        $end = $start->addMonth();

        return new self($start->utc(), $end->utc());
    }

    /**
     * One local calendar day. The daily tender report uses this.
     *
     * @param  string  $localDate  `Y-m-d`.
     *
     * @throws InvalidArgumentException if $localDate is not a real calendar date.
     */
    public static function day(string $localDate): self
    {
        $start = self::parseLocalDate($localDate);
        $end = $start->addDay();

        return new self($start->utc(), $end->utc());
    }

    /**
     * An inclusive local date range, stated the way a report filter form
     * states one: `between('2026-03-01', '2026-03-31')` covers all of March.
     *
     * THE END DATE IS INCLUSIVE AS THE CALLER STATES IT AND EXCLUSIVE AS
     * STORED. This is the one place an off-by-one would hide: `$localEnd`
     * names the last day the period should still contain, so `endsAt` is built
     * from the start of the day **after** `$localEnd`, not from `$localEnd`
     * itself. Building it from `$localEnd`'s own local midnight would silently
     * drop the whole of `$localEnd` from the period — every report calling
     * this with a form's "to" date would then exclude the day the user typed.
     *
     * @param  string  $localStart  `Y-m-d`, inclusive.
     * @param  string  $localEnd  `Y-m-d`, inclusive.
     *
     * @throws InvalidArgumentException if either date is not a real calendar date.
     */
    public static function between(string $localStart, string $localEnd): self
    {
        $start = self::parseLocalDate($localStart);
        $dayAfterEnd = self::parseLocalDate($localEnd)->addDay();

        return new self($start->utc(), $dayAfterEnd->utc());
    }

    /**
     * Add this period's range to somebody else's query, as a range comparison.
     *
     * `where($column, '>=', $startsAt)->where($column, '<', $endsAt)` is the
     * ONLY way this class ever spells the comparison. That is deliberate and
     * total: no report may reach for `whereBetween()` — inclusive on both ends,
     * see the class docblock — and none may wrap $column in a conversion
     * function such as `whereDate()`. Either would make the column's own index
     * unusable, because MySQL cannot seek on a function of a column; every
     * report built that way would silently become a table scan the day the
     * table stopped being small enough not to notice.
     *
     * `Illuminate\Database\Query\Builder`, not the Eloquent one, matching
     * `EnrollmentQueryService::joinCatalogueTo()` — the other half of a report
     * query's construction — so the two compose on the same builder. A caller
     * starting from an Eloquent model reaches this the same way that method's
     * own callers do, with `->toBase()`.
     *
     * @param  string  $column  Qualified if the query joins more than one
     *                          table carrying a same-named column, e.g.
     *                          `payments.received_at`.
     */
    public function applyTo(Builder $query, string $column): Builder
    {
        return $query
            ->where($column, '>=', $this->startsAt)
            ->where($column, '<', $this->endsAt);
    }

    /**
     * Does this period contain this instant? For assertions, and for
     * PHP-side filtering of rows already loaded.
     *
     * Half-open on the same side `applyTo()` is: `$instant` at exactly
     * `endsAt` is NOT contained, for the same reason `applyTo()` never emits
     * `<=`.
     */
    public function contains(DateTimeInterface $instant): bool
    {
        $instant = CarbonImmutable::instance($instant);

        return $instant->greaterThanOrEqualTo($this->startsAt) && $instant->lessThan($this->endsAt);
    }

    /**
     * Local midnight on a real calendar date, still on the centre's calendar —
     * callers convert to UTC themselves via `->utc()`, once they have combined
     * it with whatever else the named constructor needs.
     *
     * Shared by month() (which has already range-checked $month and always
     * passes day 1, so it cannot fail) and parseLocalDate() (which has already
     * run $year/$month/$day through checkdate()). Because both callers hand
     * this only values already known to be valid, this itself performs no
     * validation — it is where the local-to-UTC conversion happens, not where
     * a date is judged real.
     */
    private static function localMidnight(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, new DateTimeZone(CentreCalendar::TIMEZONE));
    }

    /**
     * A `Y-m-d` local date, as local midnight on the centre's calendar.
     *
     * Validated the way `Money::fromDecimal()` validates a decimal string: a
     * regex fixes the shape, then `checkdate()` refuses a shape-valid but
     * impossible date such as `2026-02-30` before it ever reaches Carbon —
     * Carbon's own `create()` would otherwise roll a day-31 in a 30-day month
     * over into the next month rather than refusing it, which is a silent
     * wrong answer rather than the loud one a malformed report filter needs.
     *
     * PUBLIC, SO THIS TASK HAS ONE DEFINITION OF A LOCAL DATE RATHER THAN TWO.
     * `OutstandingAgedReport` needs exactly this parse for its as-of date and
     * for the `due_date` values it reads back, and briefly carried a byte-for-byte
     * copy of it — justified by a comment claiming this file was out of that
     * unit's scope, which was untrue: `ReportPeriod` is part of the same task.
     * The independent pre-PR review caught the false premise. Two definitions of
     * "what a valid Y-m-d is" is precisely the shape `ChargeBalance` and
     * `CentreCalendar` exist to avoid.
     *
     * @throws InvalidArgumentException if $localDate is not a real calendar date.
     */
    public static function parseLocalDate(string $localDate): CarbonImmutable
    {
        $trimmed = trim($localDate);

        if (preg_match(self::LOCAL_DATE_PATTERN, $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Not a local calendar date: [{$localDate}]. Expected Y-m-d."
            );
        }

        $year = (int) $matches['year'];
        $month = (int) $matches['month'];
        $day = (int) $matches['day'];

        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(
                "Not a real calendar date: [{$localDate}]."
            );
        }

        return self::localMidnight($year, $month, $day);
    }
}
