<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Reports\DailyTenderReport;
use App\Domain\Finance\Reports\TenderBreakdownReport;
use App\Domain\Finance\Support\ReportPeriod;
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

    /** A standing payment with one tender of the given method. */
    $this->tenderOfMethod = function (string $receivedAtUtc, TenderMethod $method, string $amount): Payment {
        $payment = Payment::factory()->create(['received_at' => $receivedAtUtc]);

        PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'method' => $method,
            'amount' => $amount,
            'external_reference' => $method === TenderMethod::Card ? 'AUTH-0000000001' : null,
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

/*
|--------------------------------------------------------------------------
| Cash and card only — the decision, not an accident
|--------------------------------------------------------------------------
|
| Design §8 defines this report as "non-reversed **cash and card** tender
| totals for a date". TenderMethod's docblock requires any report saying
| "cash and card" to DECIDE what it does with `bank_transfer` and `other`,
| because a report filtering to the methods it happens to know about drops
| money from a total without saying so.
|
| This is the test that turns the omission into a stated rule. It is a till
| report: a bank transfer is "money arriving in the centre's account,
| evidenced outside this system", so it never crosses the desk and has no
| place in a figure someone reconciles a drawer against. The payment-method
| breakdown is where every method appears, and TenderBreakdownReportTest
| asserts that it still does — so the money is visible there, not lost.
|
| An earlier version of this report delegated without narrowing and returned
| all four methods, which the cross-review blocked against the locked design.
*/

it('reports cash and card only, excluding a bank transfer received the same day', function () {
    ($this->tenderOfMethod)('2026-03-15 09:00:00', TenderMethod::Cash, '222.000');
    ($this->tenderOfMethod)('2026-03-15 10:00:00', TenderMethod::Card, '333.000');
    ($this->tenderOfMethod)('2026-03-15 11:00:00', TenderMethod::BankTransfer, '999.000');
    ($this->tenderOfMethod)('2026-03-15 12:00:00', TenderMethod::Other, '777.000');

    $byMethod = $this->report->forDay('2026-03-15')->keyBy(
        fn (array $row): string => $row['method']->value,
    );

    expect($byMethod->keys()->sort()->values()->all())->toBe(
        ['card', 'cash'],
        'The till report returned a method that never crosses the desk.',
    );

    expect($byMethod['cash']['total']->toDecimal())->toBe('222.000')
        ->and($byMethod['card']['total']->toDecimal())->toBe('333.000');
});

it('leaves the excluded methods visible in the payment-method breakdown', function () {
    /*
     * The other half of the decision above, and the reason it is not money
     * silently dropped: what the till report omits, the breakdown still shows
     * for the same day.
     */
    ($this->tenderOfMethod)('2026-03-15 11:00:00', TenderMethod::BankTransfer, '999.000');

    $breakdown = app(TenderBreakdownReport::class)
        ->forPeriod(ReportPeriod::day('2026-03-15'))
        ->keyBy(fn (array $row): string => $row['method']->value);

    expect($breakdown->has('bank_transfer'))->toBeTrue()
        ->and($breakdown['bank_transfer']['total']->toDecimal())->toBe('999.000');
});
