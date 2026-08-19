<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Reports\ProfitReport;
use App\Domain\Finance\Reports\WageCostReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| ProfitReport — collected revenue minus finalized wage cost, composed from
| RevenueReport and WageCostReport, never re-derived
|--------------------------------------------------------------------------
|
| Design §8: "Profit | Collected revenue minus finalized wage cost for a
| period."
|
| Every fixture states a payment's received_at (a UTC instant RevenueReport
| filters through ReportPeriod) and a payroll line's posting_period_start (a
| local calendar date WageCostReport filters by plain equality) separately —
| the same discipline WageCostReportTest documents at its own file's head —
| so nothing here could accidentally pass because the two happened to line
| up on the calendar.
|
| EVERY EXPECTED FIGURE BELOW IS A LITERAL, never recomputed from the same
| rows the report read — and where the point of the test is that two
| derivations agree (behaviour 8 below), both sides are asserted against the
| same literal rather than against each other, or the test would pass for
| two equally wrong figures.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->profit = app(ProfitReport::class);

    /** A course, one batch, one enrolment, one bill — a charge a payment can allocate against. */
    $this->chargeFixture = function (): Charge {
        $course = Course::factory()->create();
        $batch = Batch::factory()->for($course)->create();
        $enrollment = Enrollment::factory()->for($batch)->create();

        return Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => '5000.000',
            'amount' => '5000.000',
        ]);
    };

    /** A payment — standing, or reversed if asked — allocated in full to $amount against $charge. */
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

    /** A finalized payroll line for a fresh employee, posting $amount to the given local month. */
    $this->wageCostFixture = function (string $amount, int $year, int $month): PayrollLine {
        $employee = User::factory()->create();

        return PayrollLine::factory()->create([
            'user_id' => $employee->getKey(),
            'computed_amount' => $amount,
            'posting_period_start' => sprintf('%04d-%02d-01', $year, $month),
            'finalized_at' => sprintf('%04d-%02d-28 09:00:00', $year, $month),
        ]);
    };
});

/*
|--------------------------------------------------------------------------
| 8. ProfitReport's wageCost and WageCostReport::totalForMonth() agree —
|    both asserted against the same literal, never against each other alone
|--------------------------------------------------------------------------
*/

it('agrees with WageCostReport on wage cost for the same month, both against the same literal', function () {
    ($this->wageCostFixture)('400.000', 2026, 7);

    $profit = $this->profit->forMonth(2026, 7);
    $wageCost = app(WageCostReport::class)->totalForMonth(2026, 7);

    expect($profit['wageCost']->toDecimal())->toBe('400.000')
        ->and($wageCost->toDecimal())->toBe('400.000');
});

/*
|--------------------------------------------------------------------------
| 9. Profit is revenue minus wage cost, against literals on all three
|--------------------------------------------------------------------------
*/

it('reports profit as revenue minus wage cost, against literals on all three', function () {
    $charge = ($this->chargeFixture)();
    ($this->payAgainst)($charge, '1000.000', '2026-08-10 10:00:00');
    ($this->wageCostFixture)('400.000', 2026, 8);

    $result = $this->profit->forMonth(2026, 8);

    expect($result['revenue']->toDecimal())->toBe('1000.000')
        ->and($result['wageCost']->toDecimal())->toBe('400.000')
        ->and($result['profit']->toDecimal())->toBe('600.000');
});

/*
|--------------------------------------------------------------------------
| 10. A reversed payment lowers profit, asserted for this report
|     specifically — two otherwise-identical months, one collected and one
|     reversed, differ by exactly the reversed amount
|--------------------------------------------------------------------------
*/

it('lowers profit by exactly a reversed payment\'s amount, asserted for this report specifically', function () {
    ($this->wageCostFixture)('200.000', 2026, 10);
    $chargeOctober = ($this->chargeFixture)();
    ($this->payAgainst)($chargeOctober, '1000.000', '2026-10-05 10:00:00');

    ($this->wageCostFixture)('200.000', 2026, 11);
    $chargeNovember = ($this->chargeFixture)();
    ($this->payAgainst)($chargeNovember, '1000.000', '2026-11-05 10:00:00', reversed: true);

    $october = $this->profit->forMonth(2026, 10)['profit'];
    $november = $this->profit->forMonth(2026, 11)['profit'];

    expect($october->subtract($november)->toDecimal())->toBe('1000.000');
});

/*
|--------------------------------------------------------------------------
| 11. A loss is reported as a negative, never clamped to zero
|--------------------------------------------------------------------------
*/

it('reports a loss as a negative figure, not clamped to zero', function () {
    ($this->wageCostFixture)('500.000', 2026, 12);

    $result = $this->profit->forMonth(2026, 12);

    expect($result['revenue']->toDecimal())->toBe('0.000')
        ->and($result['wageCost']->toDecimal())->toBe('500.000')
        ->and($result['profit']->isNegative())->toBeTrue()
        ->and($result['profit']->toDecimal())->toBe('-500.000');
});
