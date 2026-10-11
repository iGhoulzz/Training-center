<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Reports\ExpenseReport;
use App\Domain\Finance\Support\MonthRange;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| ExpenseReport — non-reversed expenses paid in whole local calendar months
|--------------------------------------------------------------------------
|
| Spec section 8, "Profit with expenses": an expense counts in the month it was
| paid, by its `paid_on` calendar date, and only while it has not been reversed.
|
| EVERY EXPECTED FIGURE BELOW IS A LITERAL, summed by hand in the comment beside
| the fixtures. None is read back from the report, and none is derived from the
| rows the report read.
|
| Fixtures are written straight to the table for the arithmetic tests, and through
| RecordExpenseAction where the point is the centre's calendar at the moment of
| recording.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->report = app(ExpenseReport::class);

    $this->category = ExpenseCategory::factory()->create();

    $this->expense = fn (string $amount, string $paidOn, bool $reversed = false): Expense => Expense::factory()
        ->for($this->category, 'category')
        ->when($reversed, fn ($factory) => $factory->reversed())
        ->create(['amount' => $amount, 'paid_on' => $paidOn]);
});

it('sums the standing expenses paid inside the month', function () {
    // October: 100.500 + 49.250 + 0.250 = 150.000
    ($this->expense)('100.500', '2026-10-05');
    ($this->expense)('49.250', '2026-10-20');
    ($this->expense)('0.250', '2026-10-31');
    // Neighbouring months, which must not leak in.
    ($this->expense)('888.000', '2026-09-30');
    ($this->expense)('999.000', '2026-11-01');

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('150.000');
});

it('returns exactly zero for a month with no expenses, never null and never an error', function () {
    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('0.000');

    ($this->expense)('75.000', '2026-09-15');

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('0.000');
});

it('counts the last day of a month in that month and the first day of the next in the next', function () {
    ($this->expense)('10.000', '2026-09-30'); // last day of September
    ($this->expense)('20.000', '2026-10-01'); // first day of October
    ($this->expense)('40.000', '2026-10-31'); // last day of October
    ($this->expense)('80.000', '2026-11-01'); // first day of November

    expect($this->report->totalForMonth(2026, 9)->toDecimal())->toBe('10.000')
        ->and($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('60.000')
        ->and($this->report->totalForMonth(2026, 11)->toDecimal())->toBe('80.000');
});

it('reads the month boundary in the centre\'s calendar, with now across UTC midnight', function () {
    /*
     * 22:30 UTC on 31 October is 00:30 on 1 November in Africa/Tripoli. The centre
     * is already in November while UTC is still in October, so a recording made
     * now can be paid on either side of the boundary the two calendars disagree
     * about.
     */
    $this->travelTo('2026-10-31 22:30:00');
    $actor = User::factory()->create(['is_active' => true]);
    $this->seed(RolePermissionSeeder::class);
    $actor->givePermissionTo('create_expense');
    $actor = $actor->fresh();

    $record = fn (string $amount, string $paidOn) => app(RecordExpenseAction::class)->execute(
        $actor,
        (int) $this->category->getKey(),
        $amount,
        $paidOn,
        'Boundary expense',
    );

    $record('40.000', '2026-10-31'); // the centre's last day of October
    $record('60.000', '2026-11-01'); // the centre's today: a November expense

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('40.000')
        ->and($this->report->totalForMonth(2026, 11)->toDecimal())->toBe('60.000');
});

it('compares paid_on with bare local dates, never with UTC instants', function () {
    ($this->expense)('10.000', '2026-10-15');
    $statements = captureStatements();

    $this->report->totalForMonth(2026, 10);

    $selects = collect($statements)->filter(fn (array $statement): bool => str_contains($statement['sql'], 'from `expenses`'));

    expect($selects)->toHaveCount(1);

    $select = $selects->first();

    // The bindings are the month's own local dates. A ReportPeriod would bind the
    // UTC instants '2026-09-30 22:00:00' and '2026-10-31 22:00:00' instead.
    expect($select['bindings'])->toBe(['2026-10-01', '2026-11-01'])
        ->and($select['sql'])->toContain('sum(amount)')
        ->and($select['sql'])->toContain('`reversed_at` is null')
        ->and($select['sql'])->toContain('`paid_on` >= ?')
        ->and($select['sql'])->toContain('`paid_on` < ?')
        // A function over the column would make its index unusable.
        ->and($select['sql'])->not->toContain('month(')
        ->and($select['sql'])->not->toContain('year(');
});

/*
|--------------------------------------------------------------------------
| A reversed expense is absent from this report
|--------------------------------------------------------------------------
*/

it('leaves a reversed expense out of the month it was paid in', function () {
    // October standing: 100.000 + 50.000 = 150.000. The reversed 500.000 is absent.
    ($this->expense)('100.000', '2026-10-05');
    ($this->expense)('50.000', '2026-10-20');
    ($this->expense)('500.000', '2026-10-10', reversed: true);

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('150.000');
});

it('drops an expense from its month when it is reversed through the Action', function () {
    $this->seed(RolePermissionSeeder::class);
    $reverser = User::factory()->create(['is_active' => true]);
    $reverser->givePermissionTo('reverse_expense');

    ($this->expense)('100.000', '2026-10-05');
    $wrong = ($this->expense)('49.250', '2026-10-20');

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('149.250');

    app(ReverseExpenseAction::class)->execute($reverser->fresh(), $wrong, 'Wrong amount.');

    // 149.250 - 49.250
    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('100.000');
});

it('moves the original month when the reversal happens later, and not the month of the reversal', function () {
    $this->seed(RolePermissionSeeder::class);
    $reverser = User::factory()->create(['is_active' => true]);
    $reverser->givePermissionTo('reverse_expense');

    $septemberExpense = ($this->expense)('300.000', '2026-09-10');
    ($this->expense)('70.000', '2026-10-10');

    $this->travelTo('2026-10-20 10:00:00');
    app(ReverseExpenseAction::class)->execute($reverser->fresh(), $septemberExpense, 'Duplicate.');

    // September loses the reversed 300.000 although it was reversed in October;
    // October is the month of the reversal and is untouched.
    expect($this->report->totalForMonth(2026, 9)->toDecimal())->toBe('0.000')
        ->and($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('70.000');
});

/*
|--------------------------------------------------------------------------
| Ranges and exactness
|--------------------------------------------------------------------------
*/

it('sums a range of whole months across a year boundary', function () {
    ($this->expense)('11.000', '2025-11-30'); // before the range
    ($this->expense)('22.000', '2025-12-01');
    ($this->expense)('33.000', '2025-12-31');
    ($this->expense)('44.000', '2026-01-01');
    ($this->expense)('55.000', '2026-01-31');
    ($this->expense)('66.000', '2026-02-01'); // after the range

    // 22 + 33 + 44 + 55
    expect($this->report->totalForMonths(MonthRange::between(2025, 12, 2026, 1))->toDecimal())->toBe('154.000');
});

it('reads a one-month range and totalForMonth identically', function () {
    ($this->expense)('12.340', '2026-10-05');

    expect($this->report->totalForMonths(MonthRange::single(2026, 10))->toDecimal())
        ->toBe($this->report->totalForMonth(2026, 10)->toDecimal())
        ->toBe('12.340');
});

it('adds dirham-sized amounts exactly, with no float in the sum', function () {
    // As floats 0.1 + 0.2 is 0.30000000000000004; as dirham it is 300.
    ($this->expense)('0.100', '2026-10-05');
    ($this->expense)('0.200', '2026-10-06');
    // And three single dirham.
    ($this->expense)('0.001', '2026-11-05');
    ($this->expense)('0.001', '2026-11-06');
    ($this->expense)('0.001', '2026-11-07');

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('0.300')
        ->and($this->report->totalForMonth(2026, 11)->toDecimal())->toBe('0.003');
});

it('sums past the largest single amount the column holds', function () {
    ($this->expense)('999999999.999', '2026-10-05');
    ($this->expense)('0.001', '2026-10-06');

    expect($this->report->totalForMonth(2026, 10)->toDecimal())->toBe('1000000000.000');
});

it('refuses a month outside 1 to 12', function (int $month) {
    expect(fn () => $this->report->totalForMonth(2026, $month))->toThrow(InvalidArgumentException::class);
})->with([0, 13]);
