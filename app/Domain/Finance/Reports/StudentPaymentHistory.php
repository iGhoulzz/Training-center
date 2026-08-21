<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One student's whole financial record: every bill raised against them and
 * every receipt they were handed, with no period filter.
 *
 * Design §8: "Per-student payment history | Every bill and every receipt."
 * "Every" is load-bearing — this is not a report a caller narrows with a date
 * range the way `RevenueReport` or `TenderBreakdownReport` are; a history is
 * the whole record or it is not a history.
 *
 * THE CONTRAST WITH OutstandingAgedReport IS DELIBERATE, ON THE SAME ROW
 * ------------------------------------------------------------------------
 * A written-off charge is EXCLUDED from the aged report and PRESENT here.
 * Both readings come from the same `charges` row and the same
 * `written_off_at` column; the two classes simply make opposite decisions
 * about what to do with it, each for its own reason (design §4): aging a
 * debt the centre has already accepted it will never collect is
 * meaningless, but erasing it from the student's history would contradict
 * "nothing is erased." Neither class asks the other what it decided.
 *
 * A REVERSED PAYMENT IS SHOWN, MARKED, AND EXCLUDED ONLY FROM THE TOTAL
 * -------------------------------------------------------------------------
 * Design §5: reversal is a set-once transition on an immutable row — nothing
 * about a reversed payment is deleted or hidden. Hiding it here would hide
 * the fact that money was taken and then voided, which is exactly the kind
 * of silent gap a payment history exists to not have. So every payment this
 * student ever made is listed, each carrying its own `reversed_at`, and
 * `collected_total` is the one figure computed with `payments.reversed_at IS
 * NULL` applied — asserted in this file directly, per design §5, rather than
 * borrowed from `ChargeBalance` or `RevenueReport`.
 *
 * THE STUDENT IS REACHED WITHOUT QUERYING ENROLMENT MODELS DIRECTLY
 * -------------------------------------------------------------------
 * `payments.student_id` is a real column — the payment's owner is never
 * supplied and never derived from a walk (design §5), so the payments half of
 * this history needs no join at all. `charges` has no such column; it reaches
 * a student only through `enrollment_id`. Reaching that path means calling
 * `EnrollmentQueryService::joinCatalogueTo()` — the published boundary,
 * exactly as `RevenueReport` calls it — and then filtering on
 * `enrollments.student_id`, a column of the join that method itself
 * contributed. This file never writes `$charge->enrollment->student`, never
 * imports the `Enrollment` model, and never calls `DB::table('enrollments')`
 * — the walk design §5 names specifically as the thing not to do.
 *
 * AGGREGATION HAPPENS IN SQL, AND EVERY MONEY FIGURE IS HYDRATED ONCE
 * ------------------------------------------------------------------------
 * A payment's receipt total is `SUM(payment_tenders.amount)`, grouped by
 * payment so a split tender (300 on card, 700 in cash) becomes the one
 * 1,000 figure the receipt actually shows — never two rows added together in
 * PHP. `collected_total` is likewise one SQL `SUM`, filtered to standing
 * payments, hydrated through `Money::fromDecimal()` exactly once.
 *
 * THE ROW-TO-ARRAY MAPPING HAPPENS INSIDE forStudent(), NOT IN A CALLEE
 * ---------------------------------------------------------------------
 * `chargeRows()` and `paymentRows()` below run the SQL and hand back the raw
 * driver rows; forStudent() does the hydration into the typed row shapes
 * itself, rather than each having its own private method that returns an
 * already-typed `Collection<int, array{...}>`. That split was tried first and
 * reproducibly tripped a PHPStan/Larastan limitation: `Collection`'s value
 * generic is invariant (its own error names this), and a private method whose
 * declared return type is `Collection<int, array{..., x: ?CarbonImmutable}>`
 * — a nullable member inside an array shape inside that generic — is
 * re-inferred rather than trusted when its result is folded into another
 * method's returned array literal, and the re-inferred type silently drops
 * the null branch. Isolated with a minimal repro before this was written:
 * the same construction passes cleanly the moment the row-to-array mapping
 * happens in the same method that assembles the final return value, which is
 * the shape kept here.
 */
final class StudentPaymentHistory
{
    public function __construct(private readonly EnrollmentQueryService $enrollments) {}

    /**
     * @return array{
     *     charges: Collection<int, array{
     *         id: int,
     *         reference: string,
     *         amount: Money,
     *         due_date: CarbonImmutable,
     *         written_off_at: ?CarbonImmutable,
     *     }>,
     *     payments: Collection<int, array{
     *         id: int,
     *         reference: string,
     *         received_at: CarbonImmutable,
     *         reversed_at: ?CarbonImmutable,
     *         total: Money,
     *     }>,
     *     collected_total: Money,
     * }
     */
    public function forStudent(int $studentId): array
    {
        $charges = new Collection;

        foreach ($this->chargeRows($studentId) as $row) {
            $charges->push([
                'id' => (int) $row->charge_id,
                'reference' => (string) $row->charge_reference,
                'amount' => Money::fromDecimal((string) $row->amount),
                /*
                 * ON THE CENTRE'S CALENDAR, NOT UTC — AND THAT IS NOT COSMETIC.
                 * `charges.due_date` is a `date`: a local calendar day with no
                 * time and no zone. `CarbonImmutable::parse()` would read it as
                 * midnight in the application's default zone (UTC), while
                 * `OutstandingAgedReport` builds local midnight in
                 * Africa/Tripoli — so the same bill came back as two different
                 * instants two hours apart depending on which report a screen
                 * asked. The independent pre-PR review caught it. The two
                 * timestamps below are genuine UTC instants and are parsed as
                 * such; only this one is a calendar date.
                 */
                'due_date' => ReportPeriod::parseLocalDate(substr((string) $row->due_date, 0, 10)),
                'written_off_at' => $this->nullableInstant($row->written_off_at),
            ]);
        }

        $payments = new Collection;

        foreach ($this->paymentRows($studentId) as $row) {
            $payments->push([
                'id' => (int) $row->payment_id,
                'reference' => (string) $row->payment_reference,
                'received_at' => CarbonImmutable::parse((string) $row->received_at),
                'reversed_at' => $this->nullableInstant($row->reversed_at),
                'total' => Money::fromDecimal((string) $row->total_amount),
            ]);
        }

        return [
            'charges' => $charges,
            'payments' => $payments,
            'collected_total' => $this->collectedTotal($studentId),
        ];
    }

    /**
     * Every bill raised against this student, raw — written off or not. See
     * the class docblock for why a write-off does not remove a row here.
     *
     * @return Collection<int, \stdClass>
     */
    private function chargeRows(int $studentId): Collection
    {
        $query = DB::table('charges')->select([
            'charges.id as charge_id',
            'charges.reference as charge_reference',
            'charges.amount',
            'charges.due_date',
            'charges.written_off_at',
        ]);

        $this->enrollments->joinCatalogueTo($query, 'charges.enrollment_id', [EnrollmentQueryService::DIMENSION_COURSE]);

        return $query
            ->where('enrollments.student_id', $studentId)
            ->orderBy('charges.due_date')
            ->get();
    }

    /**
     * Every receipt this student was handed, raw — standing or reversed. Each
     * row's `total_amount` is that one payment's tenders summed in SQL, so a
     * split tender lands as one figure.
     *
     * `LEFT JOIN` and `GROUP BY payments.id` rather than a second query keyed
     * by payment id: MySQL treats every other selected `payments` column as
     * functionally dependent on the primary key it is grouped by, so this is
     * one query rather than one query plus a per-payment lookup.
     *
     * @return Collection<int, \stdClass>
     */
    private function paymentRows(int $studentId): Collection
    {
        return DB::table('payments')
            ->leftJoin('payment_tenders', 'payment_tenders.payment_id', '=', 'payments.id')
            ->where('payments.student_id', $studentId)
            ->groupBy('payments.id')
            ->select([
                'payments.id as payment_id',
                'payments.reference as payment_reference',
                'payments.received_at',
                'payments.reversed_at',
            ])
            ->selectRaw('COALESCE(SUM(payment_tenders.amount), 0) as total_amount')
            ->orderBy('payments.received_at')
            ->get();
    }

    /**
     * What this student has actually paid — tenders on non-reversed payments
     * only (design §5), summed in SQL and hydrated once.
     *
     * The rule is asserted here, directly on `payments.reversed_at`, rather
     * than composed from `ChargeBalance` — which answers a different question
     * (what one *charge* still owes) — for the same reason `RevenueReport`
     * asserts it for itself: design §5 requires each report to state the
     * reversal filter rather than borrow it.
     */
    private function collectedTotal(int $studentId): Money
    {
        $total = DB::table('payment_tenders')
            ->join('payments', 'payments.id', '=', 'payment_tenders.payment_id')
            ->where('payments.student_id', $studentId)
            ->whereNull('payments.reversed_at')
            ->selectRaw('SUM(payment_tenders.amount) as total_amount')
            ->value('total_amount');

        return $total === null ? Money::zero() : Money::fromDecimal((string) $total);
    }

    /**
     * A nullable `datetime` column, read back from `DB::table()` as a raw
     * scalar, hydrated into a typed instant.
     *
     * A named method with an explicit `?CarbonImmutable` return type,
     * deliberately not an inline ternary: PHPStan infers an inline ternary's
     * branches as a conditional type rather than the plain union this file's
     * array shapes declare, which is a second, narrower way the same
     * invariant-generic limitation described in the class docblock can bite.
     */
    private function nullableInstant(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }
}
