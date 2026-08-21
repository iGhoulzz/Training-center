<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Reports\OutstandingAgedReport;
use App\Domain\Finance\Support\ChargeBalance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| OutstandingAgedReport — 0-30 / 31-60 / 61-90 / 91+ from due_date, excluding
| written-off charges
|--------------------------------------------------------------------------
|
| Design §8: "Outstanding balances, aged | Buckets 0–30 / 31–60 / 61–90 / 91+
| from due_date; excludes written-off." The design's own revision history
| records a defect where the buckets overlapped at day 90 — revision 5
| corrected it — so the boundary dataset below is exactly the set the spec
| requires: {0, 30, 31, 60, 61, 90, 91}. It is not shortened.
|
| No test here calls now() for anything that affects a pass/fail outcome —
| due_date is always an explicit literal derived from the fixed AS_OF
| constant below, never from the real clock — so travelTo() buys nothing
| this file would not already have without it.
|
| EVERY EXPECTED FIGURE BELOW IS A LITERAL, never recomputed from the same
| rows the report read.
*/
uses(RefreshDatabase::class);

const AS_OF = '2026-06-30';

beforeEach(function () {
    $this->report = app(OutstandingAgedReport::class);

    /**
     * A charge due exactly $daysPastDue days before AS_OF (negative means the
     * due date is still in the future), for a caller-stated $amount, with no
     * payment against it yet.
     */
    $this->chargeDueDaysAgo = function (int $daysPastDue, string $amount = '500.000'): Charge {
        return Charge::factory()->create([
            'list_price' => $amount,
            'amount' => $amount,
            'due_date' => CarbonImmutable::parse(AS_OF)->subDays($daysPastDue)->toDateString(),
        ]);
    };

    /** A payment — standing, or reversed if asked — allocating $amount against $charge. */
    $this->payAgainst = function (Charge $charge, string $amount, bool $reversed = false): Payment {
        $payment = $reversed
            ? Payment::factory()->reversed()->create()
            : Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };

    /** The row this report produced for one charge as of AS_OF, or null if absent. */
    $this->rowFor = fn (Charge $charge): ?array => $this->report->asOf(AS_OF)
        ->firstWhere('charge_id', $charge->getKey());
});

/*
|--------------------------------------------------------------------------
| 1. The boundary set the design's revision history exists because of, plus
|    the "not yet due" decision
|--------------------------------------------------------------------------
*/

it('places a charge in the correct aging bucket at each boundary offset', function (int $daysPastDue, string $expectedBucket) {
    $charge = ($this->chargeDueDaysAgo)($daysPastDue);

    $row = ($this->rowFor)($charge);

    expect($row)->not->toBeNull()
        ->and($row['bucket'])->toBe($expectedBucket)
        // Folded in here rather than as a separate test: this is the only
        // place every boundary case already has a single, unambiguous row to
        // check identity against, and it is what proves the row is "useful"
        // in the sense the report's own docblock claims — reference and
        // student, not just a bucket label.
        ->and($row['charge_reference'])->toBe($charge->reference)
        ->and($row['student_id'])->toBe($charge->enrollment->student_id);
})->with([
    'due today — 0 days past due' => [0, OutstandingAgedReport::BUCKET_0_30],
    'not yet due — 15 days in the future' => [-15, OutstandingAgedReport::BUCKET_0_30],
    '30 days past due — the top of the first bucket' => [30, OutstandingAgedReport::BUCKET_0_30],
    '31 days past due — the bottom of the second bucket' => [31, OutstandingAgedReport::BUCKET_31_60],
    '60 days past due — the top of the second bucket' => [60, OutstandingAgedReport::BUCKET_31_60],
    '61 days past due — the bottom of the third bucket' => [61, OutstandingAgedReport::BUCKET_61_90],
    '90 days past due — the top of the third bucket, where revision 5 found the overlap' => [90, OutstandingAgedReport::BUCKET_61_90],
    '91 days past due — the fourth bucket' => [91, OutstandingAgedReport::BUCKET_91_PLUS],
]);

/*
|--------------------------------------------------------------------------
| 2. No charge is ever counted twice — the property an overlapping
|    implementation loses first
|--------------------------------------------------------------------------
*/

it('lets each outstanding charge appear in exactly one bucket', function () {
    $offsets = [0, 30, 31, 60, 61, 90, 91];

    foreach ($offsets as $daysPastDue) {
        ($this->chargeDueDaysAgo)($daysPastDue);
    }

    expect($this->report->asOf(AS_OF))->toHaveCount(count($offsets));
});

/*
|--------------------------------------------------------------------------
| 3. Written off, but not paid: the debt still owes money and still drops
|    out of this report specifically (design §4)
|--------------------------------------------------------------------------
*/

it('excludes a written-off charge even though it still owes money', function () {
    $charge = Charge::factory()->writtenOff()->create([
        'list_price' => '500.000',
        'amount' => '500.000',
        'due_date' => CarbonImmutable::parse(AS_OF)->subDays(45)->toDateString(),
    ]);

    // Proves exclusion rather than a fixture that happened to be settled —
    // ChargeBalance deliberately does not subtract a write-off.
    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->isZero())->toBeFalse();

    expect(($this->rowFor)($charge))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 4. Nothing outstanding, nothing to age
|--------------------------------------------------------------------------
*/

it('excludes a fully settled charge', function () {
    $charge = ($this->chargeDueDaysAgo)(45, '500.000');
    ($this->payAgainst)($charge, '500.000');

    expect(($this->rowFor)($charge))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 5. The remaining balance, as a literal — never the original amount
|--------------------------------------------------------------------------
*/

it('shows a partially paid charge with its remaining balance as a literal', function () {
    $charge = ($this->chargeDueDaysAgo)(45, '1000.000');
    ($this->payAgainst)($charge, '300.000');

    $row = ($this->rowFor)($charge);

    expect($row)->not->toBeNull()
        ->and($row['outstanding']->toDecimal())->toBe('700.000')
        ->and($row['bucket'])->toBe(OutstandingAgedReport::BUCKET_31_60);
});

/*
|--------------------------------------------------------------------------
| 6. A reversed payment never reduces the outstanding figure, asserted for
|    this report specifically
|--------------------------------------------------------------------------
*/

it('does not let a reversed payment reduce the outstanding figure', function () {
    $charge = ($this->chargeDueDaysAgo)(45, '1000.000');
    ($this->payAgainst)($charge, '400.000', reversed: true);

    $row = ($this->rowFor)($charge);

    expect($row)->not->toBeNull()
        ->and($row['outstanding']->toDecimal())->toBe('1000.000');
});
