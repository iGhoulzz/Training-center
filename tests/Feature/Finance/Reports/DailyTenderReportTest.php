<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Reports\DailyTenderReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| DailyTenderReport — one local day of TenderBreakdownReport, live
|--------------------------------------------------------------------------
|
| Design §8: this is a LIVE figure, not a persisted reconciliation. There is no
| counted-drawer workflow, no stored expected figure, no attested difference —
| staff confirmation at the moment of recording is the source of truth. Nothing
| in this file tests for those things, because there is nothing here to test:
| the report is a thin read over TenderBreakdownReport for a single-day period,
| and its own docblock records the absence as a decision rather than an
| oversight.
|
| The boundary this file actually exists to protect is ReportPeriod::day()'s:
| a UTC-naive implementation reads "the 15th" off the timestamp's own UTC
| calendar date, which is wrong for any instant within two hours of local
| midnight. EVERY EXPECTED TOTAL BELOW IS A LITERAL.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->report = app(DailyTenderReport::class);

    /** A standing payment received at the given UTC instant, one cash tender on it. */
    $this->cashTenderReceivedAt = function (string $receivedAtUtc, string $amount): Payment {
        $payment = Payment::factory()->create(['received_at' => $receivedAtUtc]);

        PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };

    /** The same, but on a payment that has been reversed. */
    $this->reversedCashTenderReceivedAt = function (string $receivedAtUtc, string $amount): Payment {
        $payment = Payment::factory()->reversed()->create(['received_at' => $receivedAtUtc]);

        PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };
});

/*
|--------------------------------------------------------------------------
| 8. The day's totals match that day's tenders, and exclude the neighbours
|--------------------------------------------------------------------------
*/

it('matches only the requested day\'s non-reversed tenders, excluding the day before and after', function () {
    ($this->cashTenderReceivedAt)('2026-03-14 10:00:00', '111.000');
    ($this->cashTenderReceivedAt)('2026-03-15 09:00:00', '222.000');
    ($this->cashTenderReceivedAt)('2026-03-15 15:00:00', '333.000');
    ($this->cashTenderReceivedAt)('2026-03-16 10:00:00', '444.000');

    $byMethod = $this->report->forDay('2026-03-15')->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    // 222.000 + 333.000, from the 15th alone — the 14th's and 16th's amounts
    // must not be in this sum.
    expect($byMethod['cash']['total']->toDecimal())->toBe('555.000');
});

/*
|--------------------------------------------------------------------------
| 8b. A reversed payment is absent from THIS report, asserted here
|--------------------------------------------------------------------------
|
| Design §8's own row for this report reads "**Non-reversed** cash and card
| tender totals for a date", and the plan requires a reversed payment to be
| asserted absent from EACH report individually rather than inherited.
|
| This test was missing until the independent pre-PR review proved the gap by
| mutation: with the reversal filter removed from TenderBreakdownReport — the
| query this report delegates to — every other report's suite went red and
| this file stayed green. The production code was correct; the guard for it
| did not exist, while the test above was *named* "non-reversed".
|
| It matters because DailyTenderReport is the figure a till operator reconciles
| a drawer against at the end of a shift. A voided payment counted into that
| total is a discrepancy nobody can explain from the screen.
*/

it('excludes a reversed payment from the day it was received', function () {
    ($this->cashTenderReceivedAt)('2026-03-15 09:00:00', '222.000');
    ($this->reversedCashTenderReceivedAt)('2026-03-15 15:00:00', '999.000');

    $byMethod = $this->report->forDay('2026-03-15')->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    expect($byMethod['cash']['total']->toDecimal())->toBe(
        '222.000',
        'The daily tender total counted a reversed payment, so a voided receipt would inflate the drawer figure.',
    );
});

/*
|--------------------------------------------------------------------------
| 9. Either side of midnight UTC still resolves to the correct LOCAL day
|--------------------------------------------------------------------------
*/

it('assigns a tender at 23:30 UTC on the 15th to 16 March locally, not the 15th', function () {
    /*
     * Tripoli is +2 in 2026 (see ReportPeriodTest behaviour 4 for the year it
     * was not), so 2026-03-15 23:30:00 UTC is 2026-03-16 01:30:00 local — it
     * belongs to the 16th. A UTC-naive implementation that read the day off
     * the timestamp's own UTC calendar date would put this on the 15th
     * instead, which is exactly the bug ReportPeriod::day() exists to avoid.
     * `received_at` is UTC-naive-string-in, UTC-out under this app's UTC
     * timezone config, so the literal below IS the UTC instant stored.
     */
    ($this->cashTenderReceivedAt)('2026-03-15 23:30:00', '888.000');

    $sixteenth = $this->report->forDay('2026-03-16')->keyBy(
        fn (array $row): string => $row['method']->value,
    );
    $fifteenth = $this->report->forDay('2026-03-15');

    expect($sixteenth->has('cash'))->toBeTrue()
        ->and($sixteenth['cash']['total']->toDecimal())->toBe('888.000')
        ->and($fifteenth)->toBeEmpty();
});
