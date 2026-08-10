<?php

declare(strict_types=1);

use App\Domain\Finance\Support\Reference;
use App\Support\CentreCalendar;
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
 * The format matches the generator's output character for character —
 * `ENR-{year}-{id padded to 6}`, e.g. `ENR-2026-000042`. A backfilled row must
 * be indistinguishable from a generated one, because the reference is read off
 * paperwork and dictated over the phone, and because the format is asserted in
 * tests that do not know which rows came from where. Reference::format() is
 * called rather than reproduced in SQL, so "matches character for character" is
 * a shared function rather than a promise about two pieces of string handling —
 * an unpadded backfill written with LPAD was caught in review once already.
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
 * number read by whoever holds the bill. Both paths now take the year from
 * CentreCalendar::yearOf(), so they agree because they share one definition,
 * not because two files were written on the same afternoon.
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
     */
    private function referenceFor(object $row): string
    {
        $enrolledAt = new CarbonImmutable((string) $row->enrolled_at, (string) config('app.timezone'));

        return Reference::format(
            Reference::ENROLLMENT_PREFIX,
            CentreCalendar::yearOf($enrolledAt),
            (int) $row->id,
        );
    }
};
