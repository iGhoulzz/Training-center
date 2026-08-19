<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Reports\RevenueReport;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| RevenueReport — cash-basis revenue from allocations of standing payments
|--------------------------------------------------------------------------
|
| Design §8: "A dinar is revenue in the month it arrived, not the month it was
| billed." Every fixture below states a charge's due_date and a payment's
| received_at separately, so a report that filtered on the wrong column would
| be caught rather than accidentally agreeing because the two coincided.
|
| EVERY EXPECTED TOTAL BELOW IS A LITERAL, never recomputed from the same rows
| the report summed — an expectation derived from the source is an assertion
| that agrees with itself.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->report = app(RevenueReport::class);

    /**
     * A course, one batch on it (or a caller-supplied one), one enrolment, one
     * bill — everything a payment needs to allocate against. list_price and
     * amount are set explicitly and are irrelevant to the report itself, which
     * sums payment_allocations rather than charges.amount; they exist only so
     * the fixture is a bill the application could actually have issued.
     */
    $this->chargeOn = function (Course $course, ?Batch $batch = null): Charge {
        $batch ??= Batch::factory()->for($course)->create();
        $enrollment = Enrollment::factory()->for($batch)->create();

        return Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => '5000.000',
            'amount' => '5000.000',
        ]);
    };

    /**
     * A payment — standing, or reversed if asked — allocated in full to
     * $amount against $charge, received at the given local-naive UTC instant.
     */
    $this->payAgainst = function (
        Charge $charge,
        string $amount,
        string $receivedAt,
        bool $reversed = false,
    ): Payment {
        $payment = $reversed
            ? Payment::factory()->reversed()->create(['received_at' => $receivedAt])
            : Payment::factory()->create(['received_at' => $receivedAt]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };
});

/*
|--------------------------------------------------------------------------
| 1. Two courses, two literal totals, asserted per course code
|--------------------------------------------------------------------------
*/

it('sums each course\'s allocations into its own literal total', function () {
    $courseA = Course::factory()->create(['code' => 'ENG-101']);
    $courseB = Course::factory()->create(['code' => 'MTH-201']);

    $chargeA = ($this->chargeOn)($courseA);
    $chargeB = ($this->chargeOn)($courseB);

    ($this->payAgainst)($chargeA, '1000.000', '2026-03-05 10:00:00');
    ($this->payAgainst)($chargeB, '250.500', '2026-03-06 10:00:00');

    $byCode = $this->report->byCourse(ReportPeriod::month(2026, 3))->keyBy('code');

    expect($byCode['ENG-101']['total']->toDecimal())->toBe('1000.000')
        ->and($byCode['MTH-201']['total']->toDecimal())->toBe('250.500');
});

/*
|--------------------------------------------------------------------------
| 2. Cash basis: the payment's month wins, never the charge's due_date
|--------------------------------------------------------------------------
*/

it('counts a payment in the month it was received, not the month the charge was due', function () {
    /*
     * THE ASSERTION THAT FAILS IF THE PERIOD FILTER READS charges.due_date.
     * The charge is dated February; the payment that settles it arrives in
     * March. Design §8 puts the dinar in March. A period filter built on
     * due_date would put ENG-101 in February's report and leave it out of
     * March's — exactly backwards from both assertions below.
     */
    $course = Course::factory()->create(['code' => 'ENG-101']);
    $charge = ($this->chargeOn)($course);
    $charge->forceFill(['due_date' => '2026-02-15'])->saveQuietly();

    ($this->payAgainst)($charge, '1000.000', '2026-03-05 10:00:00');

    $march = $this->report->byCourse(ReportPeriod::month(2026, 3))->keyBy('code');
    $february = $this->report->byCourse(ReportPeriod::month(2026, 2))->keyBy('code');

    expect($march->has('ENG-101'))->toBeTrue()
        ->and($march['ENG-101']['total']->toDecimal())->toBe('1000.000')
        ->and($february->has('ENG-101'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 3. A reversed payment is absent, asserted for this report specifically
|--------------------------------------------------------------------------
*/

it('excludes a reversed payment\'s allocation from revenue', function () {
    $course = Course::factory()->create(['code' => 'ENG-101']);
    $charge = ($this->chargeOn)($course);

    ($this->payAgainst)($charge, '400.000', '2026-03-05 10:00:00');
    ($this->payAgainst)($charge, '600.000', '2026-03-06 10:00:00', reversed: true);

    $byCode = $this->report->byCourse(ReportPeriod::month(2026, 3))->keyBy('code');

    expect($byCode['ENG-101']['total']->toDecimal())->toBe('400.000');
});

/*
|--------------------------------------------------------------------------
| 4. byBatch() splits, byCourse() rolls up — same fixture, two groupings
|--------------------------------------------------------------------------
*/

it('keeps two batches of one course separate under byBatch and merges them under byCourse', function () {
    $course = Course::factory()->create(['code' => 'ENG-101']);
    $batchA = Batch::factory()->for($course)->create(['code' => 'ENG-101-A']);
    $batchB = Batch::factory()->for($course)->create(['code' => 'ENG-101-B']);

    $chargeA = ($this->chargeOn)($course, $batchA);
    $chargeB = ($this->chargeOn)($course, $batchB);

    ($this->payAgainst)($chargeA, '300.000', '2026-03-05 10:00:00');
    ($this->payAgainst)($chargeB, '700.000', '2026-03-06 10:00:00');

    $period = ReportPeriod::month(2026, 3);

    $byBatch = $this->report->byBatch($period)->keyBy('code');
    $byCourse = $this->report->byCourse($period)->keyBy('code');

    expect($byBatch['ENG-101-A']['total']->toDecimal())->toBe('300.000')
        ->and($byBatch['ENG-101-B']['total']->toDecimal())->toBe('700.000')
        ->and($byCourse)->toHaveCount(1)
        ->and($byCourse['ENG-101']['total']->toDecimal())->toBe('1000.000');
});

/*
|--------------------------------------------------------------------------
| The month edge, through this report's real SQL
|--------------------------------------------------------------------------
|
| The Done-when asks for boundaries at 00:00:00 local on the first of a month
| and 23:59:59 local on the last. ReportPeriodTest covers those against
| `contains()` — a PHP-side sibling of the comparison, and no report calls it.
| The independent pre-PR review pointed out that no test drove those instants
| through a query, checked by hand that the behaviour was right, and said the
| guard was missing.
|
| This is that guard. The four instants are the exact UTC edges of local March
| 2026 (Tripoli +2): 21:59:59 on 28 February is still February locally, 22:00:00
| is the first instant of March, 21:59:59 on 31 March is March's last second,
| and 22:00:00 is already April. Written as literals rather than derived from
| ReportPeriod, so a broken conversion cannot move both sides together.
*/

it('places each instant at the local month edge in exactly one month, through real SQL', function () {
    $course = Course::factory()->create(['code' => 'EDGE-1']);

    ($this->payAgainst)(($this->chargeOn)($course), '4.000', '2026-02-28 21:59:59');
    ($this->payAgainst)(($this->chargeOn)($course), '3.000', '2026-02-28 22:00:00');
    ($this->payAgainst)(($this->chargeOn)($course), '5.000', '2026-03-31 21:59:59');
    ($this->payAgainst)(($this->chargeOn)($course), '9.000', '2026-03-31 22:00:00');

    // February keeps only the 21:59:59 payment; March takes the one that
    // crosses into local March and its own last second; April takes the rest.
    expect($this->report->byCourse(ReportPeriod::month(2026, 2))->firstWhere('code', 'EDGE-1')['total']->toDecimal())
        ->toBe('4.000')
        ->and($this->report->byCourse(ReportPeriod::month(2026, 3))->firstWhere('code', 'EDGE-1')['total']->toDecimal())
        ->toBe('8.000')
        // 9.000, deliberately not equal to March's 8.000: with both months
        // carrying the same figure the assertion would survive the two being
        // swapped, which is the whole failure this test exists to catch.
        ->and($this->report->byCourse(ReportPeriod::month(2026, 4))->firstWhere('code', 'EDGE-1')['total']->toDecimal())
        ->toBe('9.000');
});
