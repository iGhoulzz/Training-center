<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\CreateExpenseCategoryAction;
use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Actions\UpdateExpenseCategoryAction;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * The three writes on the expense side that are not a reversal: record an expense,
 * create a category, update a category (which is how one is deactivated).
 *
 * AUTHORIZATION IS TESTED AS AN ACTOR HOLDING EXACTLY THE NEEDED PERMISSION AND AS
 * ONE HOLDING EVERYTHING BUT IT. Never as a super admin: that role holds every
 * permission, so it passes whichever ability the code checks and proves nothing
 * about which one it is. The permission list below is written out by hand rather
 * than read back from the seeder, so a permission the seeder dropped fails here
 * instead of agreeing with itself.
 *
 * EVERY EXPECTED VALUE IS A LITERAL written in this file. Nothing is read back from
 * the code under test.
 */
uses(RefreshDatabase::class);

const EXPENSE_SIDE_PERMISSIONS = [
    'view_any_expense_category',
    'view_expense_category',
    'create_expense_category',
    'update_expense_category',
    'view_any_expense',
    'view_expense',
    'create_expense',
    'reverse_expense',
];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // An actor holding exactly the named permissions and nothing else.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    // An actor holding every expense-side permission except the named one.
    $this->actorWithAllBut = function (string $missing): User {
        $held = array_values(array_diff(EXPENSE_SIDE_PERMISSIONS, [$missing]));

        // The control: exactly one permission is missing, so a typo in $missing
        // could not silently hand this actor all eight.
        expect($held)->toHaveCount(7);

        return ($this->actorWith)(...$held);
    };

    $this->category = ExpenseCategory::factory()->create(['name_en' => 'Rent']);

    $this->record = fn (User $actor, array $overrides = []): Expense => app(RecordExpenseAction::class)->execute(
        $actor,
        $overrides['category'] ?? (int) $this->category->getKey(),
        $overrides['amount'] ?? '125.500',
        $overrides['paid_on'] ?? '2026-10-09',
        $overrides['description'] ?? 'October rent',
    );
});

/*
|--------------------------------------------------------------------------
| RecordExpenseAction
|--------------------------------------------------------------------------
*/

it('records an expense for an actor holding exactly create_expense', function () {
    $this->travelTo('2026-10-10 09:00:00');
    $actor = ($this->actorWith)('create_expense');

    $expense = ($this->record)($actor, ['amount' => ' 125.5 ', 'description' => '  October rent  ']);

    $stored = Expense::query()->findOrFail($expense->getKey());

    expect($stored->expense_category_id)->toBe((int) $this->category->getKey())
        // Normalised to three places through Money, never through a float.
        ->and($stored->amount)->toBe('125.500')
        ->and($stored->paid_on->toDateString())->toBe('2026-10-09')
        ->and($stored->description)->toBe('October rent')
        ->and($stored->recorded_by)->toBe((int) $actor->getKey())
        ->and($stored->reversed_at)->toBeNull()
        ->and($stored->reversed_by)->toBeNull()
        ->and($stored->reversal_reason)->toBeNull();
});

it('refuses to record an expense for an actor holding everything but create_expense', function () {
    $actor = ($this->actorWithAllBut)('create_expense');

    expect(fn () => ($this->record)($actor))->toThrow(AuthorizationException::class)
        ->and(Expense::query()->count())->toBe(0);
});

it('attributes the recording to the Action actor, not to whoever holds the session', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));
    $actor = ($this->actorWith)('create_expense');

    $expense = ($this->record)($actor);

    $entry = Activity::query()
        ->where('subject_type', Expense::class)
        ->where('subject_id', $expense->getKey())
        ->where('event', 'created')
        ->sole();

    expect((int) $entry->causer_id)->toBe((int) $actor->getKey())
        ->and($entry->attribute_changes['attributes']['amount'])->toBe('125.500')
        ->and($entry->attribute_changes['attributes']['description'])->toBe('October rent');
});

it('refuses a category that is inactive, so a stale picker cannot record against it', function () {
    $actor = ($this->actorWith)('create_expense');
    $inactive = ExpenseCategory::factory()->inactive()->create();

    $record = fn () => ($this->record)($actor, ['category' => (int) $inactive->getKey()]);

    expect($record)->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);

    try {
        $record();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('expense_category_id');
    }
});

it('refuses a category that does not exist, so an expense cannot exist without one', function () {
    $actor = ($this->actorWith)('create_expense');

    expect(fn () => ($this->record)($actor, ['category' => 999999]))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);
});

it('still reads an expense\'s category after the category is deactivated', function () {
    $actor = ($this->actorWith)('create_expense');
    $expense = ($this->record)($actor);

    $this->category->update(['is_active' => false]);

    expect(Expense::query()->findOrFail($expense->getKey())->category->name_en)->toBe('Rent');
});

it('refuses an amount that is not a positive figure of at most three decimals', function (string $amount) {
    $actor = ($this->actorWith)('create_expense');

    $record = fn () => ($this->record)($actor, ['amount' => $amount]);

    expect($record)->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);

    try {
        $record();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('amount');
    }
})->with([
    'empty' => '',
    'blank' => '   ',
    'zero' => '0',
    'zero to three places' => '0.000',
    'negative' => '-5.000',
    'explicit plus' => '+5.000',
    'a fourth decimal place' => '5.1234',
    'an exponent' => '1e3',
    'a thousands separator' => '1,000.000',
    'not a number' => 'abc',
    'ten whole digits, past the column' => '1000000000.000',
]);

it('stores the smallest and the largest amount exactly, with nothing crossing a float', function (string $amount) {
    $actor = ($this->actorWith)('create_expense');

    $expense = ($this->record)($actor, ['amount' => $amount]);

    // Read raw from the table, past the model's cast, so the string the database
    // holds is what is compared.
    expect(DB::table('expenses')->where('id', $expense->getKey())->value('amount'))->toBe($amount);
})->with([
    'one dirham' => '0.001',
    'the largest the column holds' => '999999999.999',
]);

it('requires a description, and caps it at five hundred characters', function () {
    $actor = ($this->actorWith)('create_expense');

    expect(fn () => ($this->record)($actor, ['description' => '   ']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->record)($actor, ['description' => str_repeat('a', 501)]))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);

    $ok = ($this->record)($actor, ['description' => str_repeat('a', 500)]);

    expect(mb_strlen(Expense::query()->findOrFail($ok->getKey())->description))->toBe(500);
});

it('refuses a paid_on that is not a calendar date', function (string $paidOn) {
    $this->travelTo('2026-10-10 09:00:00');
    $actor = ($this->actorWith)('create_expense');

    expect(fn () => ($this->record)($actor, ['paid_on' => $paidOn]))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'month 13' => '2026-13-01',
    'wrong order' => '10/09/2026',
    'with a time' => '2026-10-09 10:00:00',
]);

/*
|--------------------------------------------------------------------------
| paid_on may not be after the CENTRE-LOCAL today
|--------------------------------------------------------------------------
|
| "Now" is placed 23:30 UTC on 10 October, which is 01:30 on 11 October in
| Africa/Tripoli. UTC's calendar says 10 October and the centre's says 11 October,
| so a rule built on the UTC date would refuse the date the till actually shows.
*/

it('allows today on the centre\'s calendar and refuses tomorrow, with now across UTC midnight', function () {
    $this->travelTo('2026-10-10 23:30:00');
    $actor = ($this->actorWith)('create_expense');

    // Local today is 2026-10-11 although the UTC date is still 2026-10-10.
    $today = ($this->record)($actor, ['paid_on' => '2026-10-11']);

    expect(Expense::query()->findOrFail($today->getKey())->paid_on->toDateString())->toBe('2026-10-11');

    $tomorrow = fn () => ($this->record)($actor, ['paid_on' => '2026-10-12']);

    expect($tomorrow)->toThrow(ValidationException::class);

    try {
        $tomorrow();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('paid_on');
    }

    expect(Expense::query()->count())->toBe(1);
});

it('allows yesterday and earlier, because an expense is often entered after the fact', function () {
    $this->travelTo('2026-10-10 23:30:00');
    $actor = ($this->actorWith)('create_expense');

    $expense = ($this->record)($actor, ['paid_on' => '2025-01-31']);

    expect(Expense::query()->findOrFail($expense->getKey())->paid_on->toDateString())->toBe('2025-01-31');
});

it('exposes the paid_on bound as one rule the form shares', function () {
    $this->travelTo('2026-10-10 23:30:00');

    expect(RecordExpenseAction::paidOnRules())->toContain('before_or_equal:2026-10-11');
});

/*
|--------------------------------------------------------------------------
| CreateExpenseCategoryAction
|--------------------------------------------------------------------------
*/

it('creates an active category for an actor holding exactly create_expense_category', function () {
    $actor = ($this->actorWith)('create_expense_category');

    $created = app(CreateExpenseCategoryAction::class)->execute($actor, '  Insurance ', 'تأمين');

    $stored = ExpenseCategory::query()->findOrFail($created->getKey());

    expect($stored->name_en)->toBe('Insurance')
        ->and($stored->name_ar)->toBe('تأمين')
        ->and($stored->is_active)->toBeTrue();
});

it('stores a blank Arabic name as not translated', function () {
    $actor = ($this->actorWith)('create_expense_category');

    $created = app(CreateExpenseCategoryAction::class)->execute($actor, 'Insurance', '   ');

    expect(ExpenseCategory::query()->findOrFail($created->getKey())->name_ar)->toBeNull();
});

it('refuses to create a category for an actor holding everything but create_expense_category', function () {
    $actor = ($this->actorWithAllBut)('create_expense_category');

    expect(fn () => app(CreateExpenseCategoryAction::class)->execute($actor, 'Insurance', null))
        ->toThrow(AuthorizationException::class)
        ->and(ExpenseCategory::query()->where('name_en', 'Insurance')->exists())->toBeFalse();
});

it('refuses a blank, an over-long, or a duplicate English category name', function () {
    $actor = ($this->actorWith)('create_expense_category');
    $create = fn (string $name) => app(CreateExpenseCategoryAction::class)->execute($actor, $name, null);

    expect(fn () => $create('   '))->toThrow(ValidationException::class)
        ->and(fn () => $create(str_repeat('a', 101)))->toThrow(ValidationException::class)
        // "Rent" exists already; the match ignores case under the table's collation.
        ->and(fn () => $create('rent'))->toThrow(ValidationException::class)
        ->and(ExpenseCategory::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| UpdateExpenseCategoryAction — rename, and deactivation
|--------------------------------------------------------------------------
*/

it('renames and deactivates a category for an actor holding exactly update_expense_category', function () {
    $actor = ($this->actorWith)('update_expense_category');

    app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Premises rent', 'إيجار', false);

    $stored = ExpenseCategory::query()->findOrFail($this->category->getKey());

    expect($stored->name_en)->toBe('Premises rent')
        ->and($stored->name_ar)->toBe('إيجار')
        ->and($stored->is_active)->toBeFalse();
});

it('refuses to update a category for an actor holding everything but update_expense_category', function () {
    $actor = ($this->actorWithAllBut)('update_expense_category');

    expect(fn () => app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Hacked', null, false))
        ->toThrow(AuthorizationException::class);

    $stored = ExpenseCategory::query()->findOrFail($this->category->getKey());

    expect($stored->name_en)->toBe('Rent')
        ->and($stored->is_active)->toBeTrue();
});

it('lets a category keep its own name when it is saved, but not take another\'s', function () {
    $actor = ($this->actorWith)('update_expense_category');
    ExpenseCategory::factory()->create(['name_en' => 'Utilities']);

    // Its own name: the unique rule ignores the row being updated.
    app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Rent', null, true);

    expect(fn () => app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Utilities', null, true))
        ->toThrow(ValidationException::class)
        ->and(ExpenseCategory::query()->findOrFail($this->category->getKey())->name_en)->toBe('Rent');
});

it('stops offering a deactivated category to new expenses and keeps it on the old ones', function () {
    $recorder = ($this->actorWith)('create_expense');
    $editor = ($this->actorWith)('update_expense_category');

    $before = ($this->record)($recorder);

    app(UpdateExpenseCategoryAction::class)->execute($editor, $this->category, 'Rent', null, false);

    expect(fn () => ($this->record)($recorder))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(1)
        ->and(Expense::query()->findOrFail($before->getKey())->expense_category_id)->toBe((int) $this->category->getKey());
});

it('locks the category row before it writes, inside a transaction', function () {
    $actor = ($this->actorWith)('update_expense_category');
    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Rent', null, false);

    $locks = locksOn($statements, 'expense_categories');

    expect($locks)->toHaveCount(1);
    expectOneLevelDeeper($locks[0], $baseline, (int) $this->category->getKey(), 'The category lock');
});

it('locks the category row when recording an expense against it, inside a transaction', function () {
    $actor = ($this->actorWith)('create_expense');
    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    ($this->record)($actor);

    $locks = locksOn($statements, 'expense_categories');

    expect($locks)->toHaveCount(1);
    expectOneLevelDeeper($locks[0], $baseline, (int) $this->category->getKey(), 'The category lock');
});

it('records category changes in the activity log under the Action actor', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));
    $actor = ($this->actorWith)('update_expense_category');

    app(UpdateExpenseCategoryAction::class)->execute($actor, $this->category, 'Rent', null, false);

    $entry = Activity::query()
        ->where('subject_type', ExpenseCategory::class)
        ->where('subject_id', $this->category->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $entry->causer_id)->toBe((int) $actor->getKey())
        ->and($entry->attribute_changes['old']['is_active'])->toBeTrue()
        ->and($entry->attribute_changes['attributes']['is_active'])->toBeFalse();
});
