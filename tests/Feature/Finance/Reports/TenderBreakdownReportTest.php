<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Reports\TenderBreakdownReport;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| TenderBreakdownReport — totals by tender, never by payment
|--------------------------------------------------------------------------
|
| Design §8 is explicit: the payment method breakdown groups by TENDER, so a
| split payment — part card, part cash — contributes to two methods rather than
| landing under whichever method happened to be recorded first. TenderMethod's
| own docblock adds the second half of the rule this file checks: bank_transfer
| and other are real cases, wider than design §8's "cash and card" prose, and a
| report that quietly narrows to the two it names in passing would drop money
| from a total without saying so.
|
| EVERY EXPECTED TOTAL BELOW IS A LITERAL, never recomputed from the same rows
| the report summed.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->report = app(TenderBreakdownReport::class);

    /** A standing (or reversed) payment received at the given instant. */
    $this->paymentReceivedAt = function (string $receivedAt, bool $reversed = false): Payment {
        return $reversed
            ? Payment::factory()->reversed()->create(['received_at' => $receivedAt])
            : Payment::factory()->create(['received_at' => $receivedAt]);
    };
});

/*
|--------------------------------------------------------------------------
| 5. A split payment contributes to two methods
|--------------------------------------------------------------------------
*/

it('splits one payment\'s 300 card and 700 cash into their own method totals', function () {
    /*
     * THE CASE DESIGN §8 NAMES EXPLICITLY. One payment, one receipt, two
     * payment_tenders rows — grouping by payment instead of by tender would
     * either merge these into one row with an arbitrary method, or overwrite
     * one total with the other. Proof obligation 3 makes exactly that mutation
     * and confirms this assertion is what catches it.
     */
    $payment = ($this->paymentReceivedAt)('2026-03-05 10:00:00');

    PaymentTender::factory()->card()->create([
        'payment_id' => $payment->getKey(),
        'amount' => '300.000',
    ]);

    PaymentTender::factory()->create([
        'payment_id' => $payment->getKey(),
        'amount' => '700.000',
        // method left at the factory default, TenderMethod::Cash.
    ]);

    $byMethod = $this->report->forPeriod(ReportPeriod::month(2026, 3))->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    expect($byMethod['card']['total']->toDecimal())->toBe('300.000')
        ->and($byMethod['cash']['total']->toDecimal())->toBe('700.000');
});

/*
|--------------------------------------------------------------------------
| 6. A reversed payment's tenders are absent
|--------------------------------------------------------------------------
*/

it('excludes a reversed payment\'s tenders from the breakdown', function () {
    $standing = ($this->paymentReceivedAt)('2026-03-05 10:00:00');
    $reversed = ($this->paymentReceivedAt)('2026-03-06 10:00:00', reversed: true);

    PaymentTender::factory()->create([
        'payment_id' => $standing->getKey(),
        'amount' => '150.000',
    ]);

    PaymentTender::factory()->create([
        'payment_id' => $reversed->getKey(),
        'amount' => '999.000',
    ]);

    $byMethod = $this->report->forPeriod(ReportPeriod::month(2026, 3))->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    expect($byMethod['cash']['total']->toDecimal())->toBe('150.000');
});

/*
|--------------------------------------------------------------------------
| 7. bank_transfer and other appear in their own rows
|--------------------------------------------------------------------------
*/

it('reports bank_transfer and other in their own rows rather than dropping them', function () {
    $payment = ($this->paymentReceivedAt)('2026-03-05 10:00:00');

    PaymentTender::factory()->bankTransfer()->create([
        'payment_id' => $payment->getKey(),
        'amount' => '450.000',
    ]);

    PaymentTender::factory()->state(['method' => TenderMethod::Other])->create([
        'payment_id' => $payment->getKey(),
        'amount' => '75.000',
    ]);

    $byMethod = $this->report->forPeriod(ReportPeriod::month(2026, 3))->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    expect($byMethod->has('bank_transfer'))->toBeTrue()
        ->and($byMethod['bank_transfer']['total']->toDecimal())->toBe('450.000')
        ->and($byMethod->has('other'))->toBeTrue()
        ->and($byMethod['other']['total']->toDecimal())->toBe('75.000');
});
