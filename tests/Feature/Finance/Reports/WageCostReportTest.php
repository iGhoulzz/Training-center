<?php

declare(strict_types=1);

use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Reports\WageCostReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| WageCostReport — per person and in total, from finalized payroll lines of
| all three run types
|--------------------------------------------------------------------------
|
| Design §8: "Wage cost per period | Per person and in total, from finalized
| payroll lines of all three run types."
|
| THE TRAP THIS FILE IS BUILT AROUND: `posting_period_start` IS A LOCAL DATE,
| NEVER AN INSTANT
| -----------------------------------------------------------------------------
| Every fixture below states `posting_period_start` as a bare `Y-m-01` string
| and never routes it through `ReportPeriod` — that column is a local calendar
| fact, already reduced to the first of its month by `FinalizePayrollRunAction`,
| and `WageCostReport::forMonth()`/`totalForMonth()` take the local month
| directly for exactly that reason. Nothing here builds a `ReportPeriod` for the
| wage-cost side.
|
| Fixtures set `computed_amount`, `posting_period_start` and `finalized_at`
| explicitly rather than relying on the factory's own "last calendar month"
| default (relative to the real clock), so nothing here is time-dependent.
|
| EVERY EXPECTED TOTAL BELOW IS A LITERAL, never recomputed from the same rows
| the report summed — an expectation derived from the source is an assertion
| that agrees with itself.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->report = app(WageCostReport::class);
});

/*
|--------------------------------------------------------------------------
| 1. Two people, two literal totals, asserted per user
|--------------------------------------------------------------------------
*/

it('sums each person\'s finalized lines into their own literal total for the month', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    PayrollLine::factory()->create([
        'user_id' => $userA->getKey(),
        'computed_amount' => '2500.000',
        'posting_period_start' => '2026-03-01',
        'finalized_at' => '2026-03-31 10:00:00',
    ]);

    PayrollLine::factory()->create([
        'user_id' => $userB->getKey(),
        'computed_amount' => '1800.500',
        'posting_period_start' => '2026-03-01',
        'finalized_at' => '2026-03-31 10:00:00',
    ]);

    $rows = $this->report->forMonth(2026, 3)->keyBy('user_id');

    expect($rows[$userA->getKey()]['total']->toDecimal())->toBe('2500.000')
        ->and($rows[$userB->getKey()]['total']->toDecimal())->toBe('1800.500');
});

/*
|--------------------------------------------------------------------------
| 2. A draft line — finalized_at null — never appears
|--------------------------------------------------------------------------
*/

it('excludes a draft line from wage cost', function () {
    $user = User::factory()->create();

    // The default factory state IS a draft: finalized_at and
    // posting_period_start are both null, the pairing the CHECK constraint
    // enforces, so this needs no override to prove the point.
    PayrollLine::factory()->create(['user_id' => $user->getKey()]);

    $rows = $this->report->forMonth(2026, 3);

    expect($rows->firstWhere('user_id', $user->getKey()))->toBeNull()
        ->and($this->report->totalForMonth(2026, 3)->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 3. All three run types contribute to one person's total
|--------------------------------------------------------------------------
*/

it('sums a salary line, an instructor line and a correction into one person\'s total for the period', function () {
    $employee = User::factory()->create();
    $period = '2026-04-01';

    // Salary.
    PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'segment_start' => '2026-04-01',
        'segment_end' => '2026-04-30',
        'computed_amount' => '2500.000',
        'posting_period_start' => $period,
        'finalized_at' => '2026-04-30 09:00:00',
    ]);

    // Instructor.
    PayrollLine::factory()->instructor()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '700.000',
        'posting_period_start' => $period,
        'finalized_at' => '2026-04-30 09:00:00',
    ]);

    // The original line a correction will target — finalized to a different
    // (irrelevant) month, so it does not itself contribute to April.
    $original = PayrollLine::factory()->instructor()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '500.000',
        'posting_period_start' => '2026-01-01',
        'finalized_at' => '2026-01-31 09:00:00',
    ]);

    // Adjustment (correction), posting to April explicitly.
    PayrollLine::factory()->adjustment('150.000')->create([
        'user_id' => $employee->getKey(),
        'corrects_payroll_line_id' => $original->getKey(),
        'posting_period_start' => $period,
        'finalized_at' => '2026-04-30 09:00:00',
    ]);

    $row = $this->report->forMonth(2026, 4)->firstWhere('user_id', $employee->getKey());

    // 2500 (salary) + 700 (instructor) + 150 (correction) = 3350.
    expect($row)->not->toBeNull()
        ->and($row['total']->toDecimal())->toBe('3350.000');
});

/*
|--------------------------------------------------------------------------
| 4. A signed adjustment moves the figure on top of computed_amount
|--------------------------------------------------------------------------
*/

it('nets a bonus and a deduction on the same line onto its computed amount', function () {
    $employee = User::factory()->create();

    $line = PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '1000.000',
        'posting_period_start' => '2026-05-01',
        'finalized_at' => '2026-05-31 09:00:00',
    ]);

    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '50.000']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '-30.000']);

    $total = $this->report->totalForMonth(2026, 5);

    expect($total->toDecimal())->toBe('1020.000');
});

/*
|--------------------------------------------------------------------------
| 5. Two adjustments on one line do not multiply the line's own amount —
|    the join fan-out this report must avoid
|--------------------------------------------------------------------------
*/

it('does not multiply a line\'s own amount by the count of its adjustments', function () {
    $employee = User::factory()->create();

    $line = PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '1000.000',
        'posting_period_start' => '2026-05-01',
        'finalized_at' => '2026-05-31 09:00:00',
    ]);

    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '10.000']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '10.000']);

    $total = $this->report->totalForMonth(2026, 5);

    // 1000 + 10 + 10 = 1020, never the fanned-out 1000 x 2 + 10 + 10 = 2020 a
    // direct join from payroll_lines to payroll_line_adjustments would
    // produce by counting the line's own amount once per adjustment row.
    expect($total->toDecimal())->toBe('1020.000')
        ->and($total->toDecimal())->not->toBe('2020.000');
});

/*
|--------------------------------------------------------------------------
| 6. A line posted to a different month is absent
|--------------------------------------------------------------------------
*/

it('excludes a finalized line posted to a different month', function () {
    $employee = User::factory()->create();

    PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '2500.000',
        'posting_period_start' => '2026-02-01',
        'finalized_at' => '2026-02-28 09:00:00',
    ]);

    $rows = $this->report->forMonth(2026, 3);

    expect($rows->firstWhere('user_id', $employee->getKey()))->toBeNull()
        ->and($this->report->totalForMonth(2026, 3)->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 7. Correcting March in June moves MARCH's wage cost, not June's — the
|    assertion task 8 (payroll runs) could not make, because no report
|    existed yet to make it through
|--------------------------------------------------------------------------
*/

it('lets a June correction move March\'s wage cost without moving June\'s', function () {
    $employee = User::factory()->create();

    // A finalized March salary line.
    $marchLine = PayrollLine::factory()->create([
        'user_id' => $employee->getKey(),
        'computed_amount' => '3000.000',
        'posting_period_start' => '2026-03-01',
        'finalized_at' => '2026-03-31 12:00:00',
    ]);

    /*
     * The correction: an adjustment line finalized in June, whose
     * corrects_payroll_line_id points at the March line. In the real
     * workflow FinalizePayrollRunAction copies posting_period_start from
     * the line being corrected rather than from the date the adjustment
     * run itself is finalized on — that mechanism is exercised end to end
     * in PayrollAdjustmentRunTest; here the correction line is built in
     * the same finished shape that mechanism produces, so this file can
     * stay focused on what the report does with the resulting row.
     */
    PayrollLine::factory()->adjustment('-125.000')->create([
        'user_id' => $employee->getKey(),
        'corrects_payroll_line_id' => $marchLine->getKey(),
        'posting_period_start' => '2026-03-01',
        'finalized_at' => '2026-06-15 09:00:00',
    ]);

    $march = $this->report->totalForMonth(2026, 3);
    $june = $this->report->totalForMonth(2026, 6);

    expect($march->toDecimal())->toBe('2875.000')
        ->and($june->toDecimal())->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| The month guard, which nothing reached
|--------------------------------------------------------------------------
|
| forMonth()/totalForMonth() document a @throws for an impossible month and
| nothing exercised it — flagged by the independent pre-PR review as an
| untested branch. A guard no test reaches is a guard that can be deleted by
| accident.
*/

it('refuses an impossible calendar month', function (int $month) {
    expect(fn () => $this->report->totalForMonth(2026, $month))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => [0],
    'thirteen' => [13],
    'negative' => [-1],
]);
