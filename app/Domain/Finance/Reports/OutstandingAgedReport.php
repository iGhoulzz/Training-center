<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What is still owed, and how long it has been owed for — aged from `due_date`
 * on the centre's local calendar, excluding debts the centre has written off.
 *
 * Design §8: "Outstanding balances, aged | Buckets 0–30 / 31–60 / 61–90 / 91+
 * from `due_date`; excludes written-off."
 *
 * OUTSTANDING COMES FROM ChargeBalance, AND FROM NOWHERE ELSE
 * -------------------------------------------------------------
 * `ChargeBalance::outstandingSql()` is composed straight into this query, both
 * to select the figure and to filter on it. There is no second subtraction
 * written here and no per-row `outstandingFor()` call — either would be either
 * a second definition of "outstanding" or an N+1 over every charge in the
 * table. This is a report, not about to act on the answer, so it reads through
 * the ordinary (non-locking) surface — see `ChargeBalance`'s own docblock for
 * why locking rows to render a column would be a real cost for nothing.
 *
 * A WRITE-OFF IS A REPORT-LEVEL FILTER, NOT A CHANGE TO WHAT IS OWED
 * ----------------------------------------------------------------------
 * `charges.written_off_at IS NULL` is asserted here, on this query, and
 * nowhere near `ChargeBalance` — which deliberately does NOT subtract a
 * write-off (design §4: writing a debt off does not pay it). The balance
 * printed on a receipt and the balance in `StudentPaymentHistory` must agree
 * for a written-off charge; only THIS report drops the row, because aging a
 * debt the centre has already accepted it will never collect would be
 * meaningless. `StudentPaymentHistory` shows the very same charge — same
 * source row, opposite treatment, both deliberate.
 *
 * AGING IS COUNTED IN WHOLE DAYS ON THE CENTRE'S LOCAL CALENDAR
 * -------------------------------------------------------------
 * `charges.due_date` is a `date` column carrying no time of day, and `asOf()`
 * takes a bare `Y-m-d` for the same reason. Both are parsed as local midnight
 * through `CentreCalendar::TIMEZONE` — never a hardcoded zone string — purely
 * so the one conversion this domain trusts is used consistently, even though a
 * calendar-date-to-calendar-date difference has no DST edge to lose here the
 * way an instant comparison would.
 *
 * THE FOUR BUCKETS DO NOT OVERLAP AND LEAVE NO GAP — AND THE PROOF IS THAT A
 * BOUNDARY EDIT CAN BREAK THAT, NOT THAT IT CANNOT
 * ---------------------------------------------------------------------------
 * This is a known past defect in this project's own design history: revision
 * 5 corrected "aging buckets overlapping at day 90". The six bounds below
 * (`MAX_DAYS_0_30`, `MIN_DAYS_31_60`, …) are six INDEPENDENT constants, each
 * named once and used once, deliberately never derived from one another. A
 * bucket is not assigned by an exhaustive match that could not overlap by
 * construction; each bucket is its own independent range check, and the four
 * are merged. That is what makes `OutstandingAgedReportTest`'s mutation —
 * widening `MAX_DAYS_0_30` from 30 to 31 while leaving `MIN_DAYS_31_60` at 31
 * — a REAL overlap a day-31 charge falls into twice, rather than a change a
 * single ordered match() would just relabel. Deriving one bound from another
 * (`MIN_DAYS_31_60 = MAX_DAYS_0_30 + 1`) would make that class of bug
 * impossible to reintroduce, and would also make it impossible for this
 * test suite to prove the class still catches it — so the bounds stay
 * independent on purpose, the same way ChargeBalance's single `NOT_REVERSED_SQL`
 * constant stays singular on purpose for the opposite reason.
 *
 * A CHARGE NOT YET DUE BELONGS IN 0–30. THIS IS A DECISION, NOT AN OVERSIGHT.
 * -----------------------------------------------------------------------------
 * `due_date` is frozen at issue as the enrolment date (design §4), and the
 * centre expects payment at or near enrolment, so there is no "not yet due"
 * state this system's workflow is built to distinguish from "just billed." A
 * charge due in the future therefore has a negative days-past-due, which
 * satisfies `<= MAX_DAYS_0_30` the same as a charge due today does, and lands
 * in the first bucket without a special case. `OutstandingAgedReportTest`
 * covers this directly rather than leaving it to be inferred from the bound.
 *
 * ENOUGH IDENTITY TO BE USEFUL, REACHED THROUGH THE PUBLISHED BOUNDARY
 * --------------------------------------------------------------------------
 * Each row names the charge (id and `CHG-` reference) and the student who owes
 * it. A charge reaches its student through `enrollment_id`, and this class
 * does not walk `$charge->enrollment->student` to get there — that is the
 * exact cross-domain query the architecture test forbids (design §5). The
 * join is contributed by `EnrollmentQueryService::joinCatalogueTo()`, the
 * published boundary Finance reads enrolments through; `student_id` is then
 * read off the join it already made, the same way `RevenueReport` reads the
 * catalogue columns off that same join rather than re-deriving them.
 */
final class OutstandingAgedReport
{
    /** 0 to 30 days past due, inclusive — and a charge not yet due at all. */
    public const BUCKET_0_30 = '0-30';

    public const BUCKET_31_60 = '31-60';

    public const BUCKET_61_90 = '61-90';

    public const BUCKET_91_PLUS = '91+';

    /**
     * The six bucket bounds, each an independent constant. See the class
     * docblock for why none of these is derived from another.
     */
    private const MAX_DAYS_0_30 = 30;

    private const MIN_DAYS_31_60 = 31;

    private const MAX_DAYS_31_60 = 60;

    private const MIN_DAYS_61_90 = 61;

    private const MAX_DAYS_61_90 = 90;

    private const MIN_DAYS_91_PLUS = 91;

    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * Every charge still owing something, not written off, one row per
     * charge, bucketed by how many whole days past `$localDate` its
     * `due_date` is.
     *
     * @param  string  $localDate  `Y-m-d`, the centre's local "today" for this report.
     * @return Collection<int, array{
     *     charge_id: int,
     *     charge_reference: string,
     *     student_id: int,
     *     due_date: CarbonImmutable,
     *     days_past_due: int,
     *     outstanding: Money,
     *     bucket: string,
     * }>
     *
     * @throws InvalidArgumentException if $localDate is not a real calendar date.
     */
    public function asOf(string $localDate): Collection
    {
        $asOf = $this->parseLocalDate($localDate);

        $query = DB::table('charges')
            ->whereNull('charges.written_off_at')
            ->select([
                'charges.id as charge_id',
                'charges.reference as charge_reference',
                'charges.due_date',
            ])
            ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
            // A correlated subquery, not an aggregate over this query, so it
            // is a plain WHERE — never a HAVING, which ChargeBalance's own
            // docblock warns resolves against the select list rather than
            // the table and would raise on `charges.amount` here.
            ->whereRaw(ChargeBalance::outstandingSql().' > 0');

        $this->enrollments->joinCatalogueTo($query, 'charges.enrollment_id', [EnrollmentQueryService::DIMENSION_COURSE]);

        $query->addSelect('enrollments.student_id')->orderBy('charges.due_date');

        $rows = $query->get()->map(function (object $row) use ($asOf): array {
            $dueDate = $this->parseLocalDate(substr((string) $row->due_date, 0, 10));

            return [
                'charge_id' => (int) $row->charge_id,
                'charge_reference' => (string) $row->charge_reference,
                'student_id' => (int) $row->student_id,
                'due_date' => $dueDate,
                'days_past_due' => $this->daysPastDue($asOf, $dueDate),
                'outstanding' => Money::fromDecimal((string) $row->{ChargeBalance::OUTSTANDING_ALIAS}),
            ];
        });

        return $this->bucketed($rows);
    }

    /**
     * Partition rows into the four buckets and stamp each with its bucket
     * label — four INDEPENDENT filters merged, deliberately not one
     * exhaustive match(). See the class docblock: that is what lets a shifted
     * boundary constant actually double-count a row instead of merely
     * relabelling it, which is the property `OutstandingAgedReportTest`'s
     * proof obligation depends on being able to break.
     *
     * @param  Collection<int, array{charge_id: int, charge_reference: string, student_id: int, due_date: CarbonImmutable, days_past_due: int, outstanding: Money}>  $rows
     * @return Collection<int, array{charge_id: int, charge_reference: string, student_id: int, due_date: CarbonImmutable, days_past_due: int, outstanding: Money, bucket: string}>
     */
    private function bucketed(Collection $rows): Collection
    {
        $byBucket = [
            self::BUCKET_0_30 => $rows->filter(
                fn (array $row): bool => $row['days_past_due'] <= self::MAX_DAYS_0_30
            ),
            self::BUCKET_31_60 => $rows->filter(
                fn (array $row): bool => $row['days_past_due'] >= self::MIN_DAYS_31_60
                    && $row['days_past_due'] <= self::MAX_DAYS_31_60
            ),
            self::BUCKET_61_90 => $rows->filter(
                fn (array $row): bool => $row['days_past_due'] >= self::MIN_DAYS_61_90
                    && $row['days_past_due'] <= self::MAX_DAYS_61_90
            ),
            self::BUCKET_91_PLUS => $rows->filter(
                fn (array $row): bool => $row['days_past_due'] >= self::MIN_DAYS_91_PLUS
            ),
        ];

        $result = collect();

        foreach ($byBucket as $bucket => $bucketRows) {
            foreach ($bucketRows as $row) {
                $row['bucket'] = $bucket;
                $result->push($row);
            }
        }

        return $result->values();
    }

    /**
     * Whole days between two local midnights: positive when $dueDate is on or
     * before $asOf (past due, or due today), negative when $dueDate is still
     * in the future (not yet due).
     *
     * `absolute: true` IS NOT THE DEFAULT AND IS NOT OPTIONAL HERE.
     * Carbon 3's `diffInDays()` defaults `$absolute` to `false` and, un-abs'd,
     * returns the difference signed the OPPOSITE way this method needs — a
     * $dueDate before $asOf came back negative under the default, which is
     * backwards for "days past due" and was caught by this file's own boundary
     * test before it ever reached a bucket assignment. Forcing the magnitude
     * explicitly and deciding the sign below by direct comparison removes the
     * dependency on remembering which way Carbon's default point the sign.
     */
    private function daysPastDue(CarbonImmutable $asOf, CarbonImmutable $dueDate): int
    {
        $magnitude = (int) $asOf->diffInDays($dueDate, absolute: true);

        return $asOf->greaterThanOrEqualTo($dueDate) ? $magnitude : -$magnitude;
    }

    /**
     * A `Y-m-d` local date, as local midnight on the centre's calendar.
     *
     * Delegates to `ReportPeriod::parseLocalDate()` rather than repeating it.
     * An earlier version of this method was a byte-for-byte copy, justified by
     * a comment saying `ReportPeriod`'s version was private and out of this
     * unit's scope — both halves of which were false, and the independent
     * pre-PR review caught it. Two parsers would mean two answers to "which
     * filter strings are legal", drifting silently the first time one is
     * widened.
     *
     * Used for the caller's as-of date and, after the time portion is trimmed,
     * for `due_date` values read back from MySQL — one parser for both, so the
     * two are guaranteed to build comparable instants on the same calendar.
     *
     * @throws InvalidArgumentException if $localDate is not a real calendar date.
     */
    private function parseLocalDate(string $localDate): CarbonImmutable
    {
        return ReportPeriod::parseLocalDate($localDate);
    }
}
