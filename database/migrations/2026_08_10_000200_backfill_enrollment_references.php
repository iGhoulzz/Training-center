<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of 4: backfill `ENR-` references onto the rows that predate the column.
 *
 * Pure DML. No schema statement belongs here — this migration is the one step
 * of the four that can be re-run, interrupted and re-run again without a
 * partially-applied ALTER left behind, and mixing a schema change into it would
 * cost exactly that property.
 *
 * `WHERE reference IS NULL` is what makes it re-runnable: a second run touches
 * nothing, and a run interrupted halfway resumes on the rows it did not reach.
 * chunkById() rather than chunk(), because this loop CHANGES the column it
 * filters on — an offset-paged chunk would renumber the remaining rows under
 * itself and skip a page of them every time.
 *
 * THE FORMAT IS A FROZEN LITERAL, NOT Reference::format()
 * -----------------------------------------------------------
 * A migration that has run is never edited, and `docs/ENGINEERING.md` requires
 * every migration to be self-contained for exactly the reason this file used to
 * violate: it imported `Reference::format()`, `Reference::ENROLLMENT_PREFIX`
 * and `CentreCalendar::yearOf()`, so a later rename of any of the three would
 * silently change what a historical `migrate:fresh` builds. The `ENR-` prefix
 * and Africa/Tripoli timezone below are the same convention the batches and
 * staff_profiles migrations already use for their status literals — spelled out
 * rather than read from application code that can move out from under them.
 *
 * The format still matches the generator's output character for character —
 * `ENR-{year}-{id padded to 6}`, e.g. `ENR-2026-000042` — as of the day this
 * migration was written. A backfilled row must be indistinguishable from a
 * generated one, because the reference is read off paperwork and dictated over
 * the phone, and `EnrollmentReferenceBackfillTest` asserts the two paths still
 * agree; an unpadded backfill written with LPAD in SQL was caught in review
 * once already, which is also why this is built in PHP with str_pad() rather
 * than reproduced as a SQL expression.
 *
 * THE YEAR IS THE CENTRE'S, ON BOTH PATHS, BY CONSTRUCTION
 * -------------------------------------------------------
 * This migration previously used MySQL's `YEAR(enrolled_at)` and its docblock
 * claimed that made the migration and the application unable to disagree about
 * which year a row belongs to. **That claim was false.** `enrolled_at` is read
 * back in the session time zone, which is not the centre's; EnrollStudentAction
 * dates a reference on the local calendar in `Africa/Tripoli`. For any row
 * enrolled in the roughly two hours between local midnight and UTC midnight on
 * 31 December, the generator produced `ENR-2027-…` and this produced
 * `ENR-2026-…` — two document numbers for rows the centre cannot tell apart.
 *
 * Design section 8 settles it in favour of the local calendar: every reporting
 * boundary is a local boundary, and a reference is a human-facing document
 * number read by whoever holds the bill. Both paths take the year from the
 * centre's calendar; this migration spells `Africa/Tripoli` literally instead
 * of calling `CentreCalendar::yearOf()`, for the same frozen-snapshot reason the
 * format above is spelled out rather than called.
 *
 * THE YEAR IS COMPUTED IN PHP, NOT BY `CONVERT_TZ`
 * ------------------------------------------------
 * MySQL's CONVERT_TZ() returns NULL for a named zone unless the server's
 * timezone tables have been loaded with `mysql_tzinfo_to_sql`, which a default
 * install has not done. It would work on the machine it was written on and
 * silently write `ENR--000042` on a fresh VPS. The value is therefore read out,
 * converted in PHP through the timezone database, and written back.
 *
 * The timestamp is parsed in `config('app.timezone')` because that is exactly
 * how Eloquent hydrates it — `asDateTime()` parses the driver's string against
 * PHP's default zone, which Laravel sets from that config value. Reading it the
 * same way the application does is what makes the two paths agree about the
 * instant before CentreCalendar is asked about the calendar.
 *
 * One UPDATE per row, deliberately. A single CASE-expression statement would be
 * fewer round-trips and would put the format back into SQL, which is the drift
 * this rewrite removes; `enrollments` holds development and test data at the
 * point this runs, so the round-trips are not what matters here.
 *
 * Deliberately not the Eloquent model. A model write fires `created`/`updated`
 * events, and `RecordsActivity` would file an activity entry per row into a log
 * that has no delete path for any role — a permanent record of a data migration.
 */
return new class extends Migration
{
    /**
     * Rows read per round trip. Large enough to be few queries, small enough
     * that an interrupted run has re-read almost nothing on resume.
     */
    private const CHUNK = 500;

    public function up(): void
    {
        DB::table('enrollments')
            ->select('id', 'enrolled_at')
            ->whereNull('reference')
            ->chunkById(self::CHUNK, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('enrollments')
                        ->where('id', $row->id)
                        ->update(['reference' => $this->referenceFor($row)]);
                }
            });
    }

    /**
     * Clears exactly the values this migration would itself have written, and
     * nothing else.
     *
     * Matching on the value this migration would produce for the row, rather
     * than blanking the column, means a value whose SHAPE differs — a
     * placeholder still mid-transaction, or anything that is not this exact
     * `ENR-{year}-{id}` string — survives the rollback. A reference the
     * application wrote for real does NOT survive on that basis: it is
     * byte-identical to what referenceFor() computes for the same row, so it
     * matches and is nulled along with a backfilled one. That is correct
     * rather than a gap — this undoes "every row has a reference", not "every
     * row this migration touched", and there is no way to tell the two apart
     * from the stored value alone once both are written in the same format.
     * This runs after step 4's down() has restored nullability, so the NULLs
     * are accepted.
     *
     * The comparison is made in PHP for the same reason the backfill is: the
     * expected value depends on the local calendar year, and no portable SQL
     * expression can produce that without the server's timezone tables.
     */
    public function down(): void
    {
        DB::table('enrollments')
            ->select('id', 'enrolled_at', 'reference')
            ->whereNotNull('reference')
            ->chunkById(self::CHUNK, function (Collection $rows): void {
                foreach ($rows as $row) {
                    if ((string) $row->reference !== $this->referenceFor($row)) {
                        continue;
                    }

                    DB::table('enrollments')
                        ->where('id', $row->id)
                        ->update(['reference' => null]);
                }
            });
    }

    /**
     * The `ENR-` value this migration owns for one raw row.
     *
     * Takes the raw stdClass the query builder yields rather than an Enrollment,
     * so nothing here can fire a model event.
     *
     * Every piece is a literal rather than a call into `Reference` or
     * `CentreCalendar` — see this migration's docblock on why a finished
     * migration must not depend on application code that can be renamed after
     * it has run. `Africa/Tripoli` is the centre's timezone (design section 8)
     * and `ENR-` is the enrolment series' prefix; both are frozen here exactly
     * as they stood on the day this migration was written, and application code
     * keeps reading the two shared classes for every path that is not this one.
     */
    private function referenceFor(object $row): string
    {
        $enrolledAt = new CarbonImmutable((string) $row->enrolled_at, (string) config('app.timezone'));

        $year = $enrolledAt->setTimezone('Africa/Tripoli')->year;

        return 'ENR-'.$year.'-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
    }
};
