<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Reports\ProfitReport;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\MonthRange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Profit with expenses (spec section 8, amended 2026-10-08, P4-T01)
|--------------------------------------------------------------------------
|
|   profit = collected revenue - finalized wage cost - non-reversed expenses paid
|            in the period
|
| EVERY EXPECTED FIGURE IS WORKED OUT BY HAND in the comment beside its fixtures
| and written as a literal. Nothing is read back from ProfitReport, ExpenseReport
| or any other report: the figure the report returns is compared with arithmetic
| done in this file, and the pre-expenses cases are compared with the OLD formula
| spelled out here, not with a call that could have changed along with the code.
|
| Revenue is placed by `received_at` (a UTC instant), wage cost by
| `posting_period_start` and expenses by `paid_on` (both local calendar dates),
| each separately, so nothing passes by the three happening to line up.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->profit = app(ProfitReport::class);

    $this->category = ExpenseCategory::factory()->create();

    /** A fresh bill a payment can allocate against. */
    $this->charge = function (): Charge {
        $course = Course::factory()->create();
        $batch = Batch::factory()->for($course)->create();
        $enrollment = Enrollment::factory()->for($batch)->create();

        return Charge::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'list_price' => '9000.000',
            'amount' => '9000.000',
        ]);
    };

    /** $amount collected against a fresh bill at the UTC instant $receivedAt. */
    $this->collect = function (string $amount, string $receivedAt): void {
        $payment = Payment::factory()->create(['received_at' => $receivedAt]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => ($this->charge)()->getKey(),
            'amount' => $amount,
        ]);
    };

    /** A finalized payroll line for a fresh employee, posting $amount to a local month. */
    $this->wages = function (string $amount, int $year, int $month): void {
        PayrollLine::factory()->create([
            'user_id' => User::factory()->create()->getKey(),
            'computed_amount' => $amount,
            'posting_period_start' => sprintf('%04d-%02d-01', $year, $month),
            'finalized_at' => sprintf('%04d-%02d-28 09:00:00', $year, $month),
        ]);
    };

    /** An expense paid on a local calendar date, standing or reversed. */
    $this->spend = fn (string $amount, string $paidOn, bool $reversed = false): Expense => Expense::factory()
        ->for($this->category, 'category')
        ->when($reversed, fn ($factory) => $factory->reversed())
        ->create(['amount' => $amount, 'paid_on' => $paidOn]);

    $this->reverser = function (): User {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('reverse_expense');

        return $user->fresh();
    };
});

/*
|--------------------------------------------------------------------------
| A month with no expenses reads exactly as it did before this task
|--------------------------------------------------------------------------
*/

it('gives a month with no expenses exactly revenue minus wage cost', function () {
    ($this->collect)('1000.000', '2026-08-10 10:00:00');
    ($this->wages)('400.000', 2026, 8);

    $result = $this->profit->forMonth(2026, 8);

    // The pre-T04 formula, by hand: 1000.000 - 400.000 = 600.000.
    expect($result['revenue']->toDecimal())->toBe('1000.000')
        ->and($result['wageCost']->toDecimal())->toBe('400.000')
        ->and($result['expenses']->toDecimal())->toBe('0.000')
        ->and($result['profit']->toDecimal())->toBe('600.000');
});

it('gives a month with no expenses its pre-expenses figure even when expenses sit in other months', function () {
    ($this->collect)('1000.000', '2026-08-10 10:00:00');
    ($this->wages)('400.000', 2026, 8);
    ($this->spend)('777.000', '2026-07-31');
    ($this->spend)('888.000', '2026-09-01');

    // Still 1000.000 - 400.000 = 600.000: neither neighbour reaches August.
    expect($this->profit->forMonth(2026, 8)['profit']->toDecimal())->toBe('600.000');
});

it('gives a month with nothing at all a profit of zero', function () {
    $result = $this->profit->forMonth(2026, 8);

    expect($result['revenue']->toDecimal())->toBe('0.000')
        ->and($result['wageCost']->toDecimal())->toBe('0.000')
        ->and($result['expenses']->toDecimal())->toBe('0.000')
        ->and($result['profit']->toDecimal())->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| The new definition
|--------------------------------------------------------------------------
*/

it('subtracts the standing expenses paid in the month from revenue minus wage cost', function () {
    ($this->collect)('3000.000', '2026-10-05 10:00:00');
    ($this->collect)('2000.000', '2026-10-20 10:00:00');
    ($this->wages)('1200.000', 2026, 10);
    ($this->spend)('300.500', '2026-10-03');
    ($this->spend)('99.500', '2026-10-28');

    $result = $this->profit->forMonth(2026, 10);

    // Revenue 3000.000 + 2000.000 = 5000.000. Expenses 300.500 + 99.500 = 400.000.
    // Profit 5000.000 - 1200.000 - 400.000 = 3400.000.
    expect($result['revenue']->toDecimal())->toBe('5000.000')
        ->and($result['wageCost']->toDecimal())->toBe('1200.000')
        ->and($result['expenses']->toDecimal())->toBe('400.000')
        ->and($result['profit']->toDecimal())->toBe('3400.000');
});

it('returns every figure as Money', function () {
    $result = $this->profit->forMonth(2026, 10);

    expect($result['revenue'])->toBeInstanceOf(Money::class)
        ->and($result['wageCost'])->toBeInstanceOf(Money::class)
        ->and($result['expenses'])->toBeInstanceOf(Money::class)
        ->and($result['profit'])->toBeInstanceOf(Money::class);
});

it('reports a loss as a negative figure when expenses outrun revenue, never clamped', function () {
    ($this->collect)('100.000', '2026-10-05 10:00:00');
    ($this->spend)('250.000', '2026-10-06');

    // 100.000 - 0.000 - 250.000 = -150.000
    $result = $this->profit->forMonth(2026, 10);

    expect($result['profit']->isNegative())->toBeTrue()
        ->and($result['profit']->toDecimal())->toBe('-150.000');
});

it('keeps dirham-sized expenses exact in the profit', function () {
    ($this->collect)('1.000', '2026-10-05 10:00:00');
    ($this->spend)('0.100', '2026-10-06');
    ($this->spend)('0.200', '2026-10-07');

    // 1.000 - 0.100 - 0.200 = 0.700, exactly.
    expect($this->profit->forMonth(2026, 10)['profit']->toDecimal())->toBe('0.700');
});

/*
|--------------------------------------------------------------------------
| Month boundaries, in the centre's calendar
|--------------------------------------------------------------------------
*/

it('counts an expense paid on the last day of a month in that month and not in the next', function () {
    ($this->collect)('1000.000', '2026-10-05 10:00:00');
    ($this->collect)('1000.000', '2026-11-05 10:00:00');
    ($this->spend)('100.000', '2026-10-31');
    ($this->spend)('300.000', '2026-11-01');

    // October: 1000.000 - 100.000 = 900.000 (the 1 November expense is not in it).
    // November: 1000.000 - 300.000 = 700.000 (the 31 October expense is not in it).
    expect($this->profit->forMonth(2026, 10)['profit']->toDecimal())->toBe('900.000')
        ->and($this->profit->forMonth(2026, 11)['profit']->toDecimal())->toBe('700.000');
});

it('places an expense recorded across UTC midnight in the centre\'s month', function () {
    $this->seed(RolePermissionSeeder::class);
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('create_expense');
    $actor = $actor->fresh();

    // 22:30 UTC on 31 October is 00:30 on 1 November in Africa/Tripoli.
    $this->travelTo('2026-10-31 22:30:00');

    app(RecordExpenseAction::class)->execute($actor, (int) $this->category->getKey(), '120.000', '2026-10-31', 'Last of October');
    app(RecordExpenseAction::class)->execute($actor, (int) $this->category->getKey(), '45.000', '2026-11-01', 'First of November');

    expect($this->profit->forMonth(2026, 10)['expenses']->toDecimal())->toBe('120.000')
        ->and($this->profit->forMonth(2026, 11)['expenses']->toDecimal())->toBe('45.000')
        ->and($this->profit->forMonth(2026, 10)['profit']->toDecimal())->toBe('-120.000')
        ->and($this->profit->forMonth(2026, 11)['profit']->toDecimal())->toBe('-45.000');
});

/*
|--------------------------------------------------------------------------
| A reversed expense is absent from Profit, asserted for this report
|--------------------------------------------------------------------------
*/

it('leaves a reversed expense out of profit', function () {
    ($this->collect)('5000.000', '2026-10-05 10:00:00');
    ($this->wages)('1200.000', 2026, 10);
    ($this->spend)('400.000', '2026-10-03');
    ($this->spend)('999.000', '2026-10-04', reversed: true);

    $result = $this->profit->forMonth(2026, 10);

    // The reversed 999.000 is in neither the expenses figure nor the profit:
    // 5000.000 - 1200.000 - 400.000 = 3400.000.
    expect($result['expenses']->toDecimal())->toBe('400.000')
        ->and($result['profit']->toDecimal())->toBe('3400.000');
});

it('gives a month whose only expense was reversed its pre-expenses profit', function () {
    ($this->collect)('1000.000', '2026-08-10 10:00:00');
    ($this->wages)('400.000', 2026, 8);
    ($this->spend)('250.000', '2026-08-12', reversed: true);

    // The old formula, by hand: 1000.000 - 400.000 = 600.000.
    expect($this->profit->forMonth(2026, 8)['profit']->toDecimal())->toBe('600.000');
});

it('moves the original month\'s profit when an expense is reversed later, through the Action', function () {
    ($this->collect)('5000.000', '2026-09-05 10:00:00');
    ($this->wages)('1000.000', 2026, 9);
    $wrong = ($this->spend)('300.500', '2026-09-10');
    ($this->spend)('100.000', '2026-09-20');

    // September before: 5000.000 - 1000.000 - (300.500 + 100.000) = 3599.500.
    expect($this->profit->forMonth(2026, 9)['profit']->toDecimal())->toBe('3599.500');

    $this->travelTo('2026-10-15 10:00:00');
    app(ReverseExpenseAction::class)->execute(($this->reverser)(), $wrong, 'Entered twice.');

    // September after: 5000.000 - 1000.000 - 100.000 = 3900.000. The closed month
    // moved, by exactly the reversed amount, and only through the reversal.
    expect($this->profit->forMonth(2026, 9)['profit']->toDecimal())->toBe('3900.000')
        // October, the month of the reversal, is untouched by it.
        ->and($this->profit->forMonth(2026, 10)['profit']->toDecimal())->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| Ranges
|--------------------------------------------------------------------------
*/

it('composes one whole range from revenue, wage cost and expenses together', function () {
    ($this->collect)('2000.000', '2025-12-15 10:00:00');
    ($this->collect)('1000.000', '2026-01-15 10:00:00');
    ($this->wages)('500.000', 2025, 12);
    ($this->wages)('100.000', 2026, 1);
    ($this->spend)('200.000', '2025-12-31');
    ($this->spend)('50.000', '2026-01-01');
    ($this->spend)('7000.000', '2026-02-01'); // after the range
    ($this->spend)('9000.000', '2025-11-30'); // before the range

    $result = $this->profit->forMonths(MonthRange::between(2025, 12, 2026, 1));

    // Revenue 3000.000, wages 600.000, expenses 250.000 -> 3000 - 600 - 250 = 2150.000.
    expect($result['revenue']->toDecimal())->toBe('3000.000')
        ->and($result['wageCost']->toDecimal())->toBe('600.000')
        ->and($result['expenses']->toDecimal())->toBe('250.000')
        ->and($result['profit']->toDecimal())->toBe('2150.000');
});

it('keeps forMonth equivalent to a one-month range, expenses included', function () {
    ($this->collect)('1000.000', '2026-02-15 10:00:00');
    ($this->spend)('125.250', '2026-02-10');

    $month = $this->profit->forMonth(2026, 2);
    $range = $this->profit->forMonths(MonthRange::single(2026, 2));

    expect($month['expenses']->toDecimal())->toBe('125.250')
        ->and($range['expenses']->toDecimal())->toBe('125.250')
        ->and($month['profit']->toDecimal())->toBe('874.750')
        ->and($range['profit']->toDecimal())->toBe('874.750');
});

it('adds exactly one expenses aggregate, however long the range', function () {
    ($this->spend)('10.000', '2016-01-15');

    $statements = captureStatements();
    $this->profit->forMonths(MonthRange::single(2016, 1));
    $oneMonth = collect($statements)->filter(fn (array $s): bool => str_contains($s['sql'], 'sum(') && str_contains($s['sql'], 'from `expenses`'))->count();

    $statements = captureStatements();
    $this->profit->forMonths(MonthRange::between(2016, 1, 2025, 12));
    $longRange = collect($statements)->filter(fn (array $s): bool => str_contains($s['sql'], 'sum(') && str_contains($s['sql'], 'from `expenses`'))->count();

    expect($oneMonth)->toBe(1)
        ->and($longRange)->toBe(1);
});
