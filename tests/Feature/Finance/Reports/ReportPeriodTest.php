<?php

declare(strict_types=1);

use App\Domain\Finance\Support\ReportPeriod;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ReportPeriod — the local-to-UTC boundary every report in design §8 shares
|--------------------------------------------------------------------------
|
| This file opens no connection: ReportPeriod converts a local calendar
| description to a UTC instant range and never touches Eloquent — even
| behaviour 9 only reads Builder::toSql(), which never executes against the
| database. RefreshDatabase is declared anyway, following MoneyTest.php,
| because DatabaseIsolationTest is deliberately fail-closed: every feature
| test either names an isolation trait or is listed there as reviewed
| read-only, and a new file is an offender until somebody decides which.
| Declaring the trait is the cheaper half of that decision and keeps the
| choice inside this file.
|
| EVERY EXPECTED UTC INSTANT BELOW IS A LITERAL STRING, never a value built
| from CentreCalendar or from ReportPeriod itself. If ReportPeriod is wrong,
| the literal must not move with it — that is what makes these tests able to
| fail at all.
*/
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| 1. A local month becomes a half-open UTC range
|--------------------------------------------------------------------------
*/

it('converts a local month to a half-open UTC range', function () {
    /*
     * Tripoli is +2 in 2026 (no DST observed that year — see behaviour 4 for
     * the year it was not), so local midnight on 1 March is 22:00 UTC the day
     * before, and local midnight on 1 April — the exclusive end — is 22:00 UTC
     * on 31 March. Measured directly against the tz database before this test
     * was written; see the proof-obligations report for the command.
     */
    $period = ReportPeriod::month(2026, 3);

    expect($period->startsAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($period->endsAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($period->startsAt->timezone->getName())->toBe('UTC')
        ->and($period->endsAt->timezone->getName())->toBe('UTC')
        ->and($period->startsAt->toDateTimeString())->toBe('2026-02-28 22:00:00')
        ->and($period->endsAt->toDateTimeString())->toBe('2026-03-31 22:00:00');
});

/*
|--------------------------------------------------------------------------
| 2. The end is exclusive
|--------------------------------------------------------------------------
*/

it('excludes the instant at endsAt itself, which is the assertion BETWEEN cannot pass', function () {
    /*
     * `BETWEEN start AND end` in SQL is inclusive on both ends, so a payment
     * recorded at the exact first instant of the following month would be
     * double-counted by a BETWEEN-based query. contains() must draw the line
     * one instant earlier than that.
     */
    $period = ReportPeriod::month(2026, 3);

    expect($period->contains($period->endsAt->subSecond()))->toBeTrue()
        ->and($period->contains($period->endsAt))->toBeFalse();

    /*
     * Exclusivity is a property of applyTo()'s SQL too, and PHP-side
     * agreement alone would not catch a `<=` slipped into that separate
     * implementation. `toSql()` never executes, so this stays a query
     * this class needs no database for.
     *
     * A bare substring check for `<` would not catch `<=` — `<=` contains
     * `<` — so this matches `<` immediately followed by the bound
     * placeholder, which `<=` never is.
     */
    $query = DB::table('payments');
    $period->applyTo($query, 'received_at');
    $sql = strtolower($query->toSql());

    expect((bool) preg_match('/received_at`\s*<\s*\?/', $sql))->toBeTrue(
        "Expected a strict < comparison against endsAt, not <=: {$sql}"
    );
});

/*
|--------------------------------------------------------------------------
| 3. Adjacent months neither overlap nor leave a gap
|--------------------------------------------------------------------------
*/

it('makes one month\'s exclusive end exactly the next month\'s inclusive start', function () {
    $february = ReportPeriod::month(2026, 2);
    $march = ReportPeriod::month(2026, 3);

    expect($february->endsAt->equalTo($march->startsAt))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 4. THE TIMEZONE-DATABASE ANCHOR
|--------------------------------------------------------------------------
*/

it('reads March 2013 as +1, the year Libya observed DST, and refuses a fixed +02:00', function () {
    /*
     * THIS IS THE WHOLE REASON THE CONVERSION GOES THROUGH THE TIMEZONE
     * DATABASE AND IS NOT ARITHMETIC. Libya ran DST in 2013; every other year
     * this test suite touches sits at a flat +2. A ReportPeriod built from
     * `new DateTimeZone('+02:00')` instead of `new DateTimeZone(Africa/Tripoli)`
     * would compute 2013-02-28 22:00:00 here — an hour early, wrong by exactly
     * the DST offset, and wrong in a way that a test pinned to any of the
     * other years in this file could never catch.
     *
     * The offset assertion is not decorative: if a future tz database revision
     * changes what history says about Libya's 2013 clocks, this assertion
     * fails on its own line and explains itself, rather than the instant
     * assertion below failing with no obvious cause.
     */
    $offsetSeconds = (new DateTime('2013-03-15', new DateTimeZone(CentreCalendar::TIMEZONE)))->getOffset();
    expect($offsetSeconds)->toBe(3600, 'Africa/Tripoli is expected to read +1 (3600s) on 2013-03-15 — '
        .'if the tz database now disagrees, the literal below is stale, not this test.');

    $period = ReportPeriod::month(2013, 3);

    expect($period->startsAt->toDateTimeString())->toBe('2013-02-28 23:00:00');
});

/*
|--------------------------------------------------------------------------
| 5. A local day
|--------------------------------------------------------------------------
*/

it('converts a local day to a half-open UTC range', function () {
    $period = ReportPeriod::day('2026-03-15');

    expect($period->startsAt->toDateTimeString())->toBe('2026-03-14 22:00:00')
        ->and($period->endsAt->toDateTimeString())->toBe('2026-03-15 22:00:00');
});

/*
|--------------------------------------------------------------------------
| 6. Either side of midnight UTC
|--------------------------------------------------------------------------
*/

it('assigns an instant either side of UTC midnight to the local day it actually fell on', function () {
    /*
     * 2026-03-15 23:30:00 UTC is 2026-03-16 01:30:00 local — Tripoli is +2 —
     * so it belongs to the 16th, not the 15th. A naive implementation that
     * takes the UTC calendar date as the local one gets this backwards, which
     * is exactly the bug this test exists to catch.
     */
    $instant = new DateTimeImmutable('2026-03-15 23:30:00', new DateTimeZone('UTC'));

    expect(ReportPeriod::day('2026-03-16')->contains($instant))->toBeTrue()
        ->and(ReportPeriod::day('2026-03-15')->contains($instant))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 7. 00:00:00 and 23:59:59 local on the month's edges
|--------------------------------------------------------------------------
*/

it('includes the first and last local second of the month, and excludes one second either side', function () {
    /*
     * Every instant here is built directly against Africa/Tripoli through
     * native PHP DateTime, independent of ReportPeriod's own conversion, so
     * this proves the boundary rather than restating it.
     */
    $tripoli = new DateTimeZone(CentreCalendar::TIMEZONE);

    $firstLocalSecond = new DateTimeImmutable('2026-03-01 00:00:00', $tripoli);
    $justBeforeFirst = $firstLocalSecond->modify('-1 second');
    $lastLocalSecond = new DateTimeImmutable('2026-03-31 23:59:59', $tripoli);
    $justAfterLast = $lastLocalSecond->modify('+1 second');

    $period = ReportPeriod::month(2026, 3);

    expect($period->contains($firstLocalSecond))->toBeTrue()
        ->and($period->contains($justBeforeFirst))->toBeFalse()
        ->and($period->contains($lastLocalSecond))->toBeTrue()
        ->and($period->contains($justAfterLast))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 8. between() treats its end date as inclusive
|--------------------------------------------------------------------------
*/

it('treats between()\'s end date as inclusive, matching the whole month it spans', function () {
    /*
     * The caller states an inclusive end date; converting it to the start of
     * the day AFTER localEnd is what makes it exclusive as stored. Getting
     * that off by one day either way would still produce a plausible-looking
     * range, which is why this is checked against month()'s own answer rather
     * than against a second hand-written literal.
     */
    $between = ReportPeriod::between('2026-03-01', '2026-03-31');
    $month = ReportPeriod::month(2026, 3);

    expect($between->startsAt->equalTo($month->startsAt))->toBeTrue()
        ->and($between->endsAt->equalTo($month->endsAt))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 9. applyTo() emits a range comparison, not date extraction
|--------------------------------------------------------------------------
*/

it('applies a range comparison against the raw column, never a date-extraction function or BETWEEN', function () {
    /*
     * Wrapping received_at in a conversion function — DATE(received_at), or
     * whatever CONVERT_TZ would have produced — makes the column's own index
     * unusable, because MySQL cannot seek on a function of a column. Every
     * report built on this would silently become a table scan. BETWEEN is
     * refused for the separate reason behaviour 2 tests: it is inclusive on
     * both ends.
     */
    $query = DB::table('payments');

    ReportPeriod::month(2026, 3)->applyTo($query, 'received_at');

    $sql = strtolower($query->toSql());

    expect(str_contains($sql, 'received_at'))->toBeTrue("Expected the column in the SQL: {$sql}")
        ->and(str_contains($sql, '>='))->toBeTrue("Expected a >= comparison in the SQL: {$sql}")
        ->and(str_contains($sql, '<'))->toBeTrue("Expected a < comparison in the SQL: {$sql}")
        ->and(str_contains($sql, 'date('))->toBeFalse("Did not expect date extraction in the SQL: {$sql}")
        ->and(str_contains($sql, 'between'))->toBeFalse("Did not expect BETWEEN in the SQL: {$sql}");
});

/*
|--------------------------------------------------------------------------
| 10. Malformed input is refused
|--------------------------------------------------------------------------
*/

it('refuses a malformed local date', function () {
    expect(fn () => ReportPeriod::day('not-a-date'))->toThrow(InvalidArgumentException::class);
});

it('refuses an impossible month', function () {
    expect(fn () => ReportPeriod::month(2026, 13))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| A reversed range is refused, not quietly emptied
|--------------------------------------------------------------------------
|
| between() took its two dates on trust. Transposed, it built a period whose
| start was after its end, and applyTo() emitted `>= <later> AND < <earlier>`
| — a contradiction matching no row. The caller got an empty report, which on
| a financial figure reads as "nothing was collected" rather than "your dates
| are the wrong way round". Caught by the cross-review.
*/

it('refuses a period whose end date is before its start', function () {
    expect(fn () => ReportPeriod::between('2026-03-31', '2026-03-01'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a single-day range where the two dates are equal', function () {
    // The boundary the guard must not over-reach: one day is a legal period,
    // and it is exactly what day() builds.
    $between = ReportPeriod::between('2026-03-01', '2026-03-01');
    $day = ReportPeriod::day('2026-03-01');

    expect($between->startsAt->toDateTimeString())->toBe($day->startsAt->toDateTimeString())
        ->and($between->endsAt->toDateTimeString())->toBe($day->endsAt->toDateTimeString());
});
