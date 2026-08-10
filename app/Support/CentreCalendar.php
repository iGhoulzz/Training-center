<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The calendar the training centre lives by, in one place.
 *
 * `config('app.timezone')` is UTC. That is the STORAGE zone — the zone every
 * timestamp column is written and read in — and it is not the centre's. A
 * walk-in enrolled at 00:30 local on 1 January is a January enrolment to the
 * person holding the receipt, while the stored timestamp still reads December.
 * Design section 8 settles which of those two answers the system gives: every
 * reporting period, and every human-facing document number, is a **local
 * calendar** fact in `Africa/Tripoli`.
 *
 * WHY THIS IS NOT IN App\Domain\Finance\Support
 * ---------------------------------------------
 * Finance is the loudest consumer — report periods, the daily tender report, the
 * `CHG-` and `RCT-` series — but it is not the only one. The `ENR-` series is
 * minted by App\Domain\Enrollment, and routes/console.php dates the nightly
 * backup window by the same calendar. Putting the definition inside one domain
 * would mean the other two either import across a domain boundary to reach it or
 * quietly keep their own copy, and a second copy is the defect this class was
 * created to remove. The centre's timezone is a property of the centre, not of
 * its billing.
 *
 * WHAT WENT WRONG BEFORE IT EXISTED
 * ---------------------------------
 * EnrollStudentAction held a private REFERENCE_TIMEZONE of `Africa/Tripoli`,
 * while the backfill migration derived the same year with MySQL's
 * `YEAR(enrolled_at)` — effectively UTC — and its docblock justified that as
 * guaranteeing the two could not disagree. They disagreed for every row enrolled
 * in the two hours before local new year: the generator produced `ENR-2027-…`
 * and the backfill `ENR-2026-…` for rows the centre could not tell apart. Both
 * now derive the year from this class, so agreement is a shared definition
 * rather than a coincidence two files have to keep remembering.
 *
 * A FIXED OFFSET IS NOT AN ACCEPTABLE SHORTCUT
 * --------------------------------------------
 * Libya sits at UTC+2 all year today, and has changed that within living memory
 * — it ran DST in 2013 and moved zone in 2012. Design section 8 requires every
 * conversion to go through the timezone database rather than through `+02:00`,
 * so that a future change is a `tzdata` update rather than a hunt through the
 * codebase. Nothing here adds hours to anything.
 *
 * MySQL's `CONVERT_TZ` IS DELIBERATELY NOT USED ANYWHERE
 * ------------------------------------------------------
 * It returns NULL unless the server's named timezone tables have been populated
 * with `mysql_tzinfo_to_sql`, which a default install has not done. A conversion
 * that silently yields NULL on one machine and the right answer on another is
 * the worst available failure mode for a document number, so conversion happens
 * in PHP — here — on every path, including the migrations.
 */
final class CentreCalendar
{
    /**
     * The centre's timezone.
     *
     * The single definition. routes/console.php still spells this literal out
     * three times for the backup schedules, which predate this class; that is a
     * known duplication rather than a second policy — the value is the same and
     * the reasoning is recorded there.
     */
    public const TIMEZONE = 'Africa/Tripoli';

    /**
     * The same instant, read on the centre's calendar.
     *
     * **Returns a CarbonImmutable, and that is the point.** The `datetime` cast
     * hands out a MUTABLE Carbon whose `setTimezone()` changes the instance in
     * place, so converting a model attribute in the obvious way rewrites the
     * attribute the caller is still holding: an Action would hand back an
     * enrolment whose `enrolled_at` silently reads in Tripoli rather than in the
     * application's own zone. `CarbonImmutable::instance()` copies before
     * converting, so the argument is never touched no matter what is passed in.
     *
     * That defensive copy used to be spelled at the one call site that needed
     * it. It lives here now so that every caller gets it, rather than each new
     * caller having to know about the hazard.
     *
     * The instant itself does not move — only the calendar it is read against.
     */
    public static function localise(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE);
    }

    /**
     * The calendar year this instant falls in, as the centre experienced it.
     *
     * What the `ENR-` / `CHG-` / `RCT-` series are dated by. Reference::format()
     * takes a year rather than a date precisely so that it owns no timezone
     * policy and the caller does; this is the policy the callers share.
     */
    public static function yearOf(DateTimeInterface $instant): int
    {
        return self::localise($instant)->year;
    }
}
