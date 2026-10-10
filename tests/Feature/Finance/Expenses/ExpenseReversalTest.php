<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Models\Expense;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * ReverseExpenseAction — undoing an expense, and the database constraints behind it.
 *
 * Reversal is a set-once lifecycle transition on an otherwise immutable row, and it
 * is how a wrong expense is corrected, because an expense is never edited. Super
 * admin only, through `reverse_expense`.
 *
 * AUTHORIZATION IS TESTED AS AN ACTOR HOLDING EXACTLY reverse_expense AND AS ONE
 * HOLDING EVERYTHING BUT IT. Never as a super admin, which passes any ability the
 * code happens to check. The seeded admin role is exercised too, as the real-world
 * actor that must be refused.
 *
 * EVERY EXPECTED VALUE IS A LITERAL written in this file.
 */
uses(RefreshDatabase::class);

const REVERSAL_SIDE_PERMISSIONS = [
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

    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->reverser = ($this->actorWith)('reverse_expense');

    $this->everythingButReverse = ($this->actorWith)(
        ...array_values(array_diff(REVERSAL_SIDE_PERMISSIONS, ['reverse_expense'])),
    );

    $this->expense = Expense::factory()->create([
        'amount' => '250.750',
        'paid_on' => '2026-09-15',
        'description' => 'Generator repair',
    ]);

    $this->reverse = fn (User $actor, ?Expense $expense = null, string $reason = 'Entered against the wrong month.'): Expense => app(ReverseExpenseAction::class)->execute(
        $actor,
        $expense ?? $this->expense,
        $reason,
    );
});

/*
|--------------------------------------------------------------------------
| Who may reverse
|--------------------------------------------------------------------------
*/

it('reverses an expense for an actor holding exactly reverse_expense', function () {
    $this->travelTo('2026-10-12 08:00:00');

    ($this->reverse)($this->reverser, null, '  Entered against the wrong month.  ');

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_at?->toDateTimeString())->toBe('2026-10-12 08:00:00')
        ->and($stored->reversed_by)->toBe((int) $this->reverser->getKey())
        ->and($stored->reversal_reason)->toBe('Entered against the wrong month.')
        ->and($stored->isReversed())->toBeTrue();
});

it('refuses an actor holding everything but reverse_expense, and writes nothing', function () {
    expect(fn () => ($this->reverse)($this->everythingButReverse))->toThrow(AuthorizationException::class);

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_at)->toBeNull()
        ->and($stored->reversed_by)->toBeNull()
        ->and($stored->reversal_reason)->toBeNull();
});

it('refuses the seeded admin role, which holds every expense ability except reverse_expense', function () {
    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');
    $admin = $admin->refresh();

    // The control: this admin really does hold the rest of the expense surface,
    // so the refusal below is about reverse_expense and not a role holding nothing.
    expect($admin->can('create_expense'))->toBeTrue()
        ->and($admin->can('view_any_expense'))->toBeTrue()
        ->and($admin->can('reverse_expense'))->toBeFalse();

    expect(fn () => ($this->reverse)($admin))->toThrow(AuthorizationException::class)
        ->and(Expense::query()->findOrFail($this->expense->getKey())->isReversed())->toBeFalse();
});

it('refuses an unauthorised actor before it reveals whether the expense exists', function () {
    $ghost = new Expense;
    $ghost->id = 987654;

    // Authorization runs first, so the answer is the same for a real row and a
    // row that does not exist: the actor learns nothing about which ids are real.
    expect(fn () => app(ReverseExpenseAction::class)->execute($this->everythingButReverse, $ghost, 'Reason'))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| What a reversal writes, and what it leaves alone
|--------------------------------------------------------------------------
*/

it('leaves every financial fact of the expense untouched', function () {
    $recorder = $this->expense->recorded_by;

    ($this->reverse)($this->reverser);

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->amount)->toBe('250.750')
        ->and($stored->paid_on->toDateString())->toBe('2026-09-15')
        ->and($stored->description)->toBe('Generator repair')
        ->and($stored->expense_category_id)->toBe((int) $this->expense->expense_category_id)
        ->and($stored->recorded_by)->toBe((int) $recorder);
});

it('reverses only the expense it was given', function () {
    $other = Expense::factory()->create();

    ($this->reverse)($this->reverser);

    expect(Expense::query()->findOrFail($other->getKey())->isReversed())->toBeFalse();
});

it('requires a reason, and caps it at a thousand characters', function (string $reason) {
    expect(fn () => ($this->reverse)($this->reverser, null, $reason))->toThrow(ValidationException::class);

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_at)->toBeNull()
        ->and($stored->reversed_by)->toBeNull()
        ->and($stored->reversal_reason)->toBeNull();
})->with([
    'empty' => '',
    'blank' => "  \t  ",
    'too long' => str_repeat('a', 1001),
]);

it('accepts a reason of exactly a thousand characters', function () {
    ($this->reverse)($this->reverser, null, str_repeat('a', 1000));

    expect(mb_strlen((string) Expense::query()->findOrFail($this->expense->getKey())->reversal_reason))->toBe(1000);
});

/*
|--------------------------------------------------------------------------
| Set-once: a second reversal is refused, including from a stale copy
|--------------------------------------------------------------------------
*/

it('refuses a second reversal and keeps the first one\'s actor, time and reason', function () {
    $this->travelTo('2026-10-12 08:00:00');
    ($this->reverse)($this->reverser, null, 'First reason.');

    $second = ($this->actorWith)('reverse_expense');
    $this->travelTo('2026-10-13 09:00:00');

    $attempt = fn () => ($this->reverse)($second, null, 'Second reason.');

    expect($attempt)->toThrow(ValidationException::class);

    try {
        $attempt();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('expense');
    }

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_by)->toBe((int) $this->reverser->getKey())
        ->and($stored->reversed_at?->toDateTimeString())->toBe('2026-10-12 08:00:00')
        ->and($stored->reversal_reason)->toBe('First reason.');
});

it('refuses a stale copy loaded before another session reversed the expense', function () {
    // A second browser tab: this copy was loaded while the expense was standing,
    // and still says so even after the row has been reversed underneath it.
    $stale = Expense::query()->findOrFail($this->expense->getKey());

    $this->travelTo('2026-10-12 08:00:00');
    ($this->reverse)($this->reverser, null, 'First reason.');

    expect($stale->isReversed())->toBeFalse('The control: the stale copy must really claim "not reversed".');

    $second = ($this->actorWith)('reverse_expense');
    $this->travelTo('2026-10-13 09:00:00');

    expect(fn () => ($this->reverse)($second, $stale, 'Second reason.'))
        ->toThrow(ValidationException::class);

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_by)->toBe((int) $this->reverser->getKey())
        ->and($stored->reversed_at?->toDateTimeString())->toBe('2026-10-12 08:00:00')
        ->and($stored->reversal_reason)->toBe('First reason.');
});

it('takes a locking read of the expense inside a transaction, before it writes', function () {
    $baseline = DB::transactionLevel();
    $statements = captureStatements();

    ($this->reverse)($this->reverser);

    $locks = locksOn($statements, 'expenses');
    $writes = writesTo($statements, 'expenses');

    expect($locks)->toHaveCount(1)
        ->and($writes)->toHaveCount(1);

    expectOneLevelDeeper($locks[0], $baseline, (int) $this->expense->getKey(), 'The expense lock');
    expectOneLevelDeeper($writes[0], $baseline, (int) $this->expense->getKey(), 'The reversal write');

    // The lock comes first: the decision is made on the row it returns.
    $all = collect($statements)->pluck('sql')->values();
    $lockAt = $all->search(fn (string $sql): bool => str_contains($sql, ' for update') && str_contains($sql, 'from `expenses`'));
    $writeAt = $all->search(fn (string $sql): bool => str_starts_with($sql, 'update `expenses`'));

    expect($lockAt)->toBeLessThan($writeAt);
});

/*
|--------------------------------------------------------------------------
| Attribution and the activity log
|--------------------------------------------------------------------------
*/

it('attributes the reversal to the Action actor, not to whoever holds the session', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));

    ($this->reverse)($this->reverser, null, 'Wrong month.');

    $entry = Activity::query()
        ->where('subject_type', Expense::class)
        ->where('subject_id', $this->expense->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $entry->causer_id)->toBe((int) $this->reverser->getKey())
        // The reason travels into the append-only log with the actor.
        ->and($entry->attribute_changes['attributes']['reversal_reason'])->toBe('Wrong month.')
        ->and($entry->attribute_changes['attributes']['reversed_by'])->toBe((int) $this->reverser->getKey())
        ->and($entry->attribute_changes['attributes'])->toHaveKey('reversed_at')
        // Only the reversal moved: no financial fact is in the diff.
        ->and($entry->attribute_changes['attributes'])->not->toHaveKey('amount')
        ->and($entry->attribute_changes['attributes'])->not->toHaveKey('paid_on');
});

/*
|--------------------------------------------------------------------------
| The database refuses what the Action would never write
|--------------------------------------------------------------------------
|
| Raw statements, past every model and Action: an Action's check protects the paths
| that go through it, a constraint protects the rest.
*/

it('rejects a partial reversal at the database on insert', function (array $reversal) {
    // ACTOR and NOW are markers, resolved here because a dataset is built before
    // the test's own fixtures exist.
    $reversal = array_map(fn (mixed $value): mixed => match ($value) {
        'ACTOR' => $this->reverser->getKey(),
        'NOW' => '2026-10-12 08:00:00',
        default => $value,
    }, $reversal);

    $row = [
        'expense_category_id' => $this->expense->expense_category_id,
        'amount' => '10.000',
        'paid_on' => '2026-09-01',
        'description' => 'Raw row',
        'recorded_by' => $this->expense->recorded_by,
        'created_at' => now(),
        'updated_at' => now(),
        ...$reversal,
    ];

    expect(fn () => DB::table('expenses')->insert($row))->toThrow(QueryException::class)
        ->and(DB::table('expenses')->where('description', 'Raw row')->exists())->toBeFalse();
})->with([
    'a time and nothing else' => [['reversed_at' => 'NOW']],
    'an actor and nothing else' => [['reversed_by' => 'ACTOR']],
    'a reason and nothing else' => [['reversal_reason' => 'Because']],
    'a time and an actor, no reason' => [['reversed_at' => 'NOW', 'reversed_by' => 'ACTOR']],
    'a time and a reason, no actor' => [['reversed_at' => 'NOW', 'reversal_reason' => 'Because']],
    'an actor and a reason, no time' => [['reversed_by' => 'ACTOR', 'reversal_reason' => 'Because']],
]);

it('accepts a complete reversal inserted raw, so the constraint refuses the partial shapes and not the feature', function () {
    DB::table('expenses')->insert([
        'expense_category_id' => $this->expense->expense_category_id,
        'amount' => '10.000',
        'paid_on' => '2026-09-01',
        'description' => 'Raw complete row',
        'recorded_by' => $this->expense->recorded_by,
        'reversed_at' => '2026-10-12 08:00:00',
        'reversed_by' => $this->reverser->getKey(),
        'reversal_reason' => 'Because',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('expenses')->where('description', 'Raw complete row')->exists())->toBeTrue();
});

it('rejects a partial reversal at the database on update', function (array $partial) {
    $partial = array_map(fn (mixed $value): mixed => $value === 'ACTOR' ? $this->reverser->getKey() : $value, $partial);

    expect(fn () => DB::table('expenses')->where('id', $this->expense->getKey())->update($partial))
        ->toThrow(QueryException::class);

    $stored = Expense::query()->findOrFail($this->expense->getKey());

    expect($stored->reversed_at)->toBeNull()
        ->and($stored->reversed_by)->toBeNull()
        ->and($stored->reversal_reason)->toBeNull();
})->with([
    'a time and nothing else' => [['reversed_at' => '2026-10-12 08:00:00']],
    'an actor and nothing else' => [['reversed_by' => 'ACTOR']],
    'a reason and nothing else' => [['reversal_reason' => 'Because']],
]);

it('rejects clearing part of an existing reversal at the database', function () {
    ($this->reverse)($this->reverser);

    expect(fn () => DB::table('expenses')->where('id', $this->expense->getKey())->update(['reversal_reason' => null]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('expenses')->where('id', $this->expense->getKey())->update(['reversed_at' => null]))
        ->toThrow(QueryException::class)
        ->and(Expense::query()->findOrFail($this->expense->getKey())->isReversed())->toBeTrue();
});

it('rejects a zero or negative amount at the database', function (string $amount) {
    expect(fn () => DB::table('expenses')->insert([
        'expense_category_id' => $this->expense->expense_category_id,
        'amount' => $amount,
        'paid_on' => '2026-09-01',
        'description' => 'Raw row',
        'recorded_by' => $this->expense->recorded_by,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('expenses')->where('id', $this->expense->getKey())->update(['amount' => $amount]))
        ->toThrow(QueryException::class)
        ->and(Expense::query()->findOrFail($this->expense->getKey())->amount)->toBe('250.750');
})->with([
    'zero' => '0.000',
    'negative' => '-1.000',
]);

it('refuses to delete a category that has an expense on record, at the database', function () {
    expect(fn () => DB::table('expense_categories')->where('id', $this->expense->expense_category_id)->delete())
        ->toThrow(QueryException::class);
});
