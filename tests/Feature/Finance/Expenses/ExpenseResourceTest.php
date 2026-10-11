<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\CreateExpenseCategory;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\EditExpenseCategory;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Policies\ExpenseCategoryPolicy;
use App\Domain\Finance\Policies\ExpensePolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * The expense side of the panel as it is actually reachable: over HTTP and through
 * the real Livewire components.
 *
 * "A correct policy nobody consults denies nothing" (CourseResourceTest). So every
 * permission claim here is driven through the page or the record action with an
 * actor holding EXACTLY the needed permission and one holding one fewer — never a
 * super admin for an "allowed" claim, which holds every permission and so proves
 * nothing about which one is checked. The absence of an edit and a delete path is
 * asserted by trying to reach one, even as a super admin, rather than by reading
 * the class.
 *
 * EVERY EXPECTED VALUE IS A LITERAL written in this file.
 */
uses(RefreshDatabase::class);

const EXPENSE_RESOURCE_PERMISSIONS = [
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

    $this->roleUser = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($user, $role);

        return $user->fresh();
    };

    // Exactly the named permissions, plus the panel door.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('access_admin_panel', ...$permissions);

        return $user->fresh();
    };

    // A page of the expense resource needs the list permission as well as its own:
    // Filament gates every page of a resource on viewAny first. So "exactly the
    // needed permission" for a page is its own plus view_any_expense, and the
    // negative twin below holds everything else.
    $this->expenseActorWith = fn (string ...$permissions): User => ($this->actorWith)('view_any_expense', ...$permissions);
    $this->categoryActorWith = fn (string ...$permissions): User => ($this->actorWith)('view_any_expense_category', ...$permissions);

    // The panel door and every expense-side permission except the named one.
    $this->actorWithAllBut = function (string $missing): User {
        $held = array_values(array_diff(EXPENSE_RESOURCE_PERMISSIONS, [$missing]));

        expect($held)->toHaveCount(7);

        return ($this->actorWith)(...$held);
    };

    $this->category = ExpenseCategory::factory()->create(['name_en' => 'Rent']);

    $this->standing = Expense::factory()->for($this->category, 'category')->create([
        'amount' => '1200.500',
        'paid_on' => '2026-09-01',
        'description' => 'September rent',
    ]);
});

/*
|--------------------------------------------------------------------------
| Policies resolve unregistered, and answer by permission
|--------------------------------------------------------------------------
*/

it('resolves both policies by convention, with no registration', function () {
    expect(Gate::getPolicyFor(Expense::class))->toBeInstanceOf(ExpensePolicy::class)
        ->and(Gate::getPolicyFor(ExpenseCategory::class))->toBeInstanceOf(ExpenseCategoryPolicy::class)
        ->and(file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->not->toContain('ExpensePolicy')
        ->not->toContain('ExpenseCategoryPolicy');
});

it('answers each expense ability from exactly one permission', function (string $permission, string $ability, string $subject) {
    $holder = ($this->actorWith)($permission);
    $withoutIt = ($this->actorWithAllBut)($permission);
    $target = $subject === 'expense' ? $this->standing : $this->category;
    $class = $subject === 'expense' ? Expense::class : ExpenseCategory::class;

    $arguments = in_array($ability, ['viewAny', 'create', 'reverse'], true) ? $class : $target;

    expect(Gate::forUser($holder)->allows($ability, $arguments))->toBeTrue()
        ->and(Gate::forUser($withoutIt)->allows($ability, $arguments))->toBeFalse();
})->with([
    'list expenses' => ['view_any_expense', 'viewAny', 'expense'],
    'open an expense' => ['view_expense', 'view', 'expense'],
    'record an expense' => ['create_expense', 'create', 'expense'],
    'reverse an expense' => ['reverse_expense', 'reverse', 'expense'],
    'list categories' => ['view_any_expense_category', 'viewAny', 'category'],
    'open a category' => ['view_expense_category', 'view', 'category'],
    'add a category' => ['create_expense_category', 'create', 'category'],
    'edit or deactivate a category' => ['update_expense_category', 'update', 'category'],
]);

/*
|--------------------------------------------------------------------------
| Who reaches which page over HTTP
|--------------------------------------------------------------------------
*/

it('opens the expense list only to a holder of view_any_expense', function () {
    $this->actingAs(($this->actorWith)('view_any_expense'))->get('/admin/expenses')->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('view_any_expense'))->get('/admin/expenses')->assertForbidden();
});

it('opens the record-an-expense page only to a holder of create_expense', function () {
    $this->actingAs(($this->expenseActorWith)('create_expense'))->get('/admin/expenses/create')->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('create_expense'))->get('/admin/expenses/create')->assertForbidden();
});

it('opens one expense only to a holder of view_expense', function () {
    $url = "/admin/expenses/{$this->standing->getKey()}";

    $this->actingAs(($this->expenseActorWith)('view_expense'))->get($url)->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('view_expense'))->get($url)->assertForbidden();
});

it('opens the category list only to a holder of view_any_expense_category', function () {
    $this->actingAs(($this->actorWith)('view_any_expense_category'))->get('/admin/expense-categories')->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('view_any_expense_category'))->get('/admin/expense-categories')->assertForbidden();
});

it('opens the new-category page only to a holder of create_expense_category', function () {
    $this->actingAs(($this->categoryActorWith)('create_expense_category'))->get('/admin/expense-categories/create')->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('create_expense_category'))->get('/admin/expense-categories/create')->assertForbidden();
});

it('opens the edit-category page only to a holder of update_expense_category', function () {
    $url = "/admin/expense-categories/{$this->category->getKey()}/edit";

    $this->actingAs(($this->categoryActorWith)('update_expense_category'))->get($url)->assertSuccessful();
    $this->actingAs(($this->actorWithAllBut)('update_expense_category'))->get($url)->assertForbidden();
});

it('denies staff every expense surface, who hold nothing financial at all', function () {
    $staff = ($this->roleUser)('staff');

    foreach (['/admin/expenses', '/admin/expenses/create', "/admin/expenses/{$this->standing->getKey()}", '/admin/expense-categories', '/admin/expense-categories/create'] as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }
});

it('lets the seeded admin role record and read, and not reverse', function () {
    $admin = ($this->roleUser)('admin');

    $this->actingAs($admin)->get('/admin/expenses')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/expenses/create')->assertSuccessful();
    $this->actingAs($admin)->get('/admin/expense-categories')->assertSuccessful();

    Livewire::actingAs($admin)
        ->test(ListExpenses::class)
        ->assertTableActionHidden('reverse', $this->standing);
});

/*
|--------------------------------------------------------------------------
| The expense list
|--------------------------------------------------------------------------
*/

it('lists expenses with their category, a formatted amount and their reversed state', function () {
    $reversed = Expense::factory()->for($this->category, 'category')->reversed()->create([
        'amount' => '75.250',
        'description' => 'Duplicate invoice',
    ]);

    Livewire::actingAs(($this->actorWith)('view_any_expense'))
        ->test(ListExpenses::class)
        ->assertCanSeeTableRecords([$this->standing, $reversed])
        ->assertSee('Rent')
        ->assertSee('1200.500 LYD')
        ->assertSee('75.250 LYD')
        ->assertTableColumnStateSet('reversed', false, $this->standing)
        ->assertTableColumnStateSet('reversed', true, $reversed);
});

it('filters the list by category and by reversed state', function () {
    $utilities = ExpenseCategory::factory()->create(['name_en' => 'Utilities']);
    $power = Expense::factory()->for($utilities, 'category')->create(['description' => 'Power bill']);
    $reversed = Expense::factory()->for($this->category, 'category')->reversed()->create();

    Livewire::actingAs(($this->actorWith)('view_any_expense'))
        ->test(ListExpenses::class)
        ->filterTable('expense_category_id', $utilities->getKey())
        ->assertCanSeeTableRecords([$power])
        ->assertCanNotSeeTableRecords([$this->standing, $reversed])
        ->removeTableFilter('expense_category_id')
        ->filterTable('reversed_at', true)
        ->assertCanSeeTableRecords([$reversed])
        ->assertCanNotSeeTableRecords([$this->standing, $power])
        ->filterTable('reversed_at', false)
        ->assertCanSeeTableRecords([$this->standing, $power])
        ->assertCanNotSeeTableRecords([$reversed]);
});

it('keeps listing an expense whose category has since been deactivated', function () {
    $this->category->update(['is_active' => false]);

    Livewire::actingAs(($this->actorWith)('view_any_expense'))
        ->test(ListExpenses::class)
        ->assertCanSeeTableRecords([$this->standing])
        ->assertSee('Rent');
});

/*
|--------------------------------------------------------------------------
| Recording an expense
|--------------------------------------------------------------------------
*/

it('records an expense through the form, as the signed-in actor', function () {
    $this->travelTo('2026-10-10 09:00:00');
    $actor = ($this->expenseActorWith)('create_expense');

    Livewire::actingAs($actor)
        ->test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => $this->category->getKey(),
            'amount' => '300.5',
            'paid_on' => '2026-10-09',
            'description' => 'October rent',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::query()->where('description', 'October rent')->sole();

    expect($expense->amount)->toBe('300.500')
        ->and($expense->paid_on->toDateString())->toBe('2026-10-09')
        ->and($expense->expense_category_id)->toBe((int) $this->category->getKey())
        ->and($expense->recorded_by)->toBe((int) $actor->getKey())
        ->and($expense->isReversed())->toBeFalse();

    $entry = Activity::query()->where('subject_type', Expense::class)->where('subject_id', $expense->getKey())->sole();

    expect((int) $entry->causer_id)->toBe((int) $actor->getKey());
});

it('cannot be told who recorded the expense or that it is already reversed', function () {
    $this->travelTo('2026-10-10 09:00:00');
    $actor = ($this->expenseActorWith)('create_expense');
    $someoneElse = User::factory()->create();

    Livewire::actingAs($actor)
        ->test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => $this->category->getKey(),
            'amount' => '10.000',
            'paid_on' => '2026-10-09',
            'description' => 'Crafted',
            // Fields the form does not have. They must be ignored, not honoured.
            'recorded_by' => $someoneElse->getKey(),
            'reversed_at' => '2026-10-09 10:00:00',
            'reversed_by' => $someoneElse->getKey(),
            'reversal_reason' => 'Pre-reversed',
        ])
        ->call('create');

    $expense = Expense::query()->where('description', 'Crafted')->sole();

    expect($expense->recorded_by)->toBe((int) $actor->getKey())
        ->and($expense->reversed_at)->toBeNull()
        ->and($expense->reversed_by)->toBeNull()
        ->and($expense->reversal_reason)->toBeNull();
});

it('does not offer a deactivated category, and refuses one submitted anyway', function () {
    $this->travelTo('2026-10-10 09:00:00');
    $inactive = ExpenseCategory::factory()->inactive()->create(['name_en' => 'Retired heading']);
    $actor = ($this->expenseActorWith)('create_expense');

    $page = Livewire::actingAs($actor)->test(CreateExpense::class);

    $page->assertFormFieldExists('expense_category_id', function (Select $field) use ($inactive): bool {
        $options = $field->getOptions();

        return array_key_exists($this->category->getKey(), $options)
            && ! array_key_exists($inactive->getKey(), $options);
    });

    $page->fillForm([
        'expense_category_id' => $inactive->getKey(),
        'amount' => '10.000',
        'paid_on' => '2026-10-09',
        'description' => 'Into a retired heading',
    ])->call('create')->assertHasFormErrors(['expense_category_id']);

    expect(Expense::query()->where('description', 'Into a retired heading')->exists())->toBeFalse();
});

it('refuses an expense without a category', function () {
    $this->travelTo('2026-10-10 09:00:00');

    Livewire::actingAs(($this->expenseActorWith)('create_expense'))
        ->test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => null,
            'amount' => '10.000',
            'paid_on' => '2026-10-09',
            'description' => 'No heading',
        ])
        ->call('create')
        ->assertHasFormErrors(['expense_category_id' => 'required']);

    expect(Expense::query()->where('description', 'No heading')->exists())->toBeFalse();
});

it('refuses an amount that is not a positive figure of at most three decimals', function (string $amount) {
    $this->travelTo('2026-10-10 09:00:00');

    Livewire::actingAs(($this->expenseActorWith)('create_expense'))
        ->test(CreateExpense::class)
        ->fillForm([
            'expense_category_id' => $this->category->getKey(),
            'amount' => $amount,
            'paid_on' => '2026-10-09',
            'description' => 'Bad amount',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(Expense::query()->where('description', 'Bad amount')->exists())->toBeFalse();
})->with([
    'zero' => '0',
    'negative' => '-5.000',
    'a fourth decimal' => '5.1234',
    'an exponent' => '1e3',
    'text' => 'abc',
    'past the column' => '1000000000.000',
]);

it('keeps the amount field a string end to end, never installing a float cast', function () {
    $this->travelTo('2026-10-10 09:00:00');

    $page = Livewire::actingAs(($this->expenseActorWith)('create_expense'))
        ->test(CreateExpense::class)
        ->fillForm(['amount' => '1.000']);

    $page->assertFormFieldExists('amount', fn (TextInput $field): bool => ! $field->isNumeric());

    // A float cast would turn "1.000" into 1.0 and then "1": the trailing zeros
    // are the dirham, and they are exactly what must survive.
    $state = $page->instance()->form->getRawState();

    expect($state['amount'])->toBeString()->toBe('1.000');
});

it('refuses a date paid after the centre\'s today and accepts today, with now across UTC midnight', function () {
    // 23:30 UTC on 10 October is 01:30 on 11 October in Africa/Tripoli.
    $this->travelTo('2026-10-10 23:30:00');
    $actor = ($this->expenseActorWith)('create_expense');
    $form = fn (string $paidOn, string $description): array => [
        'expense_category_id' => $this->category->getKey(),
        'amount' => '10.000',
        'paid_on' => $paidOn,
        'description' => $description,
    ];

    Livewire::actingAs($actor)
        ->test(CreateExpense::class)
        ->fillForm($form('2026-10-12', 'Tomorrow'))
        ->call('create')
        ->assertHasFormErrors(['paid_on']);

    Livewire::actingAs($actor)
        ->test(CreateExpense::class)
        ->fillForm($form('2026-10-11', 'Today at the centre'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->where('description', 'Tomorrow')->exists())->toBeFalse()
        ->and(Expense::query()->where('description', 'Today at the centre')->exists())->toBeTrue();
});

it('defaults the date paid to the centre\'s today, not UTC\'s', function () {
    $this->travelTo('2026-10-10 23:30:00');

    $page = Livewire::actingAs(($this->expenseActorWith)('create_expense'))->test(CreateExpense::class);

    expect($page->instance()->form->getRawState()['paid_on'])->toBe('2026-10-11');
});

it('refuses to record when the actor lacks create_expense, even through the component', function () {
    Livewire::actingAs(($this->actorWithAllBut)('create_expense'))
        ->test(CreateExpense::class)
        ->assertForbidden();

    expect(Expense::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The reverse action
|--------------------------------------------------------------------------
*/

it('shows the reverse action to a holder of exactly reverse_expense and hides it from one holding everything else', function () {
    Livewire::actingAs(($this->actorWith)('view_any_expense', 'reverse_expense'))
        ->test(ListExpenses::class)
        ->assertTableActionVisible('reverse', $this->standing);

    Livewire::actingAs(($this->actorWithAllBut)('reverse_expense'))
        ->test(ListExpenses::class)
        ->assertTableActionHidden('reverse', $this->standing);
});

it('reverses an expense through the table action, with a reason, as the signed-in actor', function () {
    $actor = ($this->actorWith)('view_any_expense', 'reverse_expense');

    Livewire::actingAs($actor)
        ->test(ListExpenses::class)
        ->callTableAction('reverse', $this->standing, ['reason' => 'Paid twice by mistake.'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('expenses.reversed_successfully'));

    $stored = Expense::query()->findOrFail($this->standing->getKey());

    expect($stored->isReversed())->toBeTrue()
        ->and($stored->reversed_by)->toBe((int) $actor->getKey())
        ->and($stored->reversal_reason)->toBe('Paid twice by mistake.')
        // Nothing the expense says happened was rewritten.
        ->and($stored->amount)->toBe('1200.500')
        ->and($stored->paid_on->toDateString())->toBe('2026-09-01')
        ->and($stored->description)->toBe('September rent');
});

it('requires a reason to reverse', function () {
    Livewire::actingAs(($this->actorWith)('view_any_expense', 'reverse_expense'))
        ->test(ListExpenses::class)
        ->callTableAction('reverse', $this->standing, ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect(Expense::query()->findOrFail($this->standing->getKey())->isReversed())->toBeFalse();
});

it('does not reverse for an actor without reverse_expense, however the action is reached', function () {
    $actor = ($this->actorWithAllBut)('reverse_expense');

    $list = Livewire::actingAs($actor)->test(ListExpenses::class);

    try {
        $list->call('mountTableAction', 'reverse', (string) $this->standing->getKey())
            ->set('mountedActions.0.data.reason', 'Crafted')
            ->call('callMountedAction');
    } catch (Throwable) {
        // A component that refuses an unauthorised action outright is also a refusal.
    }

    expect(Expense::query()->findOrFail($this->standing->getKey())->isReversed())->toBeFalse();
});

it('hides the reverse action once an expense is reversed', function () {
    $actor = ($this->actorWith)('view_any_expense', 'reverse_expense');
    $reversed = Expense::factory()->for($this->category, 'category')->reversed()->create();

    Livewire::actingAs($actor)
        ->test(ListExpenses::class)
        ->assertTableActionVisible('reverse', $this->standing)
        ->assertTableActionHidden('reverse', $reversed);
});

it('refuses a reversal that loses a race after the modal was opened', function () {
    $actor = ($this->actorWith)('view_any_expense', 'reverse_expense');
    $other = ($this->actorWith)('reverse_expense');

    $list = Livewire::actingAs($actor)->test(ListExpenses::class);

    // The modal is open while the expense stands...
    $list->mountTableAction('reverse', $this->standing);

    // ...and somebody else reverses it before this form is submitted.
    app(ReverseExpenseAction::class)->execute($other, $this->standing, 'First reversal.');

    // Filament re-resolves the record when the modal is submitted, finds the
    // action no longer visible and drops the call. That is a UX courtesy; the
    // guard is ReverseExpenseAction's locking read, proved against a stale model
    // in ExpenseReversalTest. What this test pins is the outcome the user cares
    // about: the late submission neither crashes nor re-stamps the reversal.
    $list->setTableActionData(['reason' => 'Second reversal.'])->callMountedTableAction();

    $stored = Expense::query()->findOrFail($this->standing->getKey());

    expect($stored->reversed_by)->toBe((int) $other->getKey())
        ->and($stored->reversal_reason)->toBe('First reversal.');
});

it('offers the same reverse action on the view page', function () {
    Livewire::actingAs(($this->expenseActorWith)('view_expense', 'reverse_expense'))
        ->test(ViewExpense::class, ['record' => $this->standing->getKey()])
        ->assertActionVisible('reverse')
        ->callAction('reverse', ['reason' => 'From the view page.'])
        ->assertNotified(__('expenses.reversed_successfully'));

    expect(Expense::query()->findOrFail($this->standing->getKey())->reversal_reason)->toBe('From the view page.');

    Livewire::actingAs(($this->actorWithAllBut)('reverse_expense'))
        ->test(ViewExpense::class, ['record' => Expense::factory()->create()->getKey()])
        ->assertActionHidden('reverse');
});

it('shows the reversal on the view page', function () {
    $reversed = Expense::factory()->for($this->category, 'category')->reversed()->create([
        'amount' => '88.000',
        'reversal_reason' => 'Booked to the wrong year.',
    ]);

    Livewire::actingAs(($this->expenseActorWith)('view_expense'))
        ->test(ViewExpense::class, ['record' => $reversed->getKey()])
        ->assertSee('88.000 LYD')
        ->assertSee('Booked to the wrong year.');
});

/*
|--------------------------------------------------------------------------
| An expense is never edited and never deleted — for anyone, even a super admin
|--------------------------------------------------------------------------
*/

it('registers no edit page for an expense', function () {
    expect(array_keys(ExpenseResource::getPages()))->toBe(['index', 'create', 'view']);

    $this->actingAs(($this->roleUser)('super_admin'));

    $this->get("/admin/expenses/{$this->standing->getKey()}/edit")->assertNotFound();
    $this->get("/admin/expenses/{$this->standing->getKey()}/delete")->assertNotFound();
    $this->delete("/admin/expenses/{$this->standing->getKey()}")->assertStatus(405);
    $this->put("/admin/expenses/{$this->standing->getKey()}")->assertStatus(405);
});

it('offers no edit, delete or bulk action on the list, to an admin or a super admin', function (string $role) {
    $list = Livewire::actingAs(($this->roleUser)($role))->test(ListExpenses::class);

    $list->assertTableActionDoesNotExist('edit', null, $this->standing)
        ->assertTableActionDoesNotExist('delete', null, $this->standing)
        ->assertTableActionDoesNotExist('forceDelete', null, $this->standing)
        ->assertTableActionDoesNotExist('restore', null, $this->standing)
        ->assertTableActionDoesNotExist('replicate', null, $this->standing)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    // And the raw server path, which does not consult what the page chose to render.
    foreach (['delete', 'edit'] as $name) {
        try {
            $list->call('mountAction', $name, [], ['table' => true, 'recordKey' => (string) $this->standing->getKey()]);
        } catch (Throwable) {
            // A component that refuses an unknown action outright is also a refusal.
        }
    }

    $stored = Expense::query()->findOrFail($this->standing->getKey());

    expect($stored->amount)->toBe('1200.500')
        ->and($stored->description)->toBe('September rent');
})->with(['admin', 'super_admin']);

it('offers no edit or delete action on the view page, to an admin or a super admin', function (string $role) {
    Livewire::actingAs(($this->roleUser)($role))
        ->test(ViewExpense::class, ['record' => $this->standing->getKey()])
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');

    expect(Expense::query()->whereKey($this->standing->getKey())->exists())->toBeTrue();
})->with(['admin', 'super_admin']);

it('refuses edit and delete even to an actor granted the abilities that are never seeded', function () {
    // These permission names do not exist in the seeded set; creating them here and
    // granting them is the strongest version of "the policy refuses anyway".
    foreach (['update_expense', 'delete_expense', 'delete_any_expense', 'force_delete_expense', 'restore_expense'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $actor = ($this->actorWith)(
        ...EXPENSE_RESOURCE_PERMISSIONS,
        ...['update_expense', 'delete_expense', 'delete_any_expense', 'force_delete_expense', 'restore_expense'],
    );

    // Filament's can*() helpers resolve against the signed-in user.
    $this->actingAs($actor);

    expect(Gate::forUser($actor)->denies('update', $this->standing))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('delete', $this->standing))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('deleteAny', Expense::class))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('forceDelete', $this->standing))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('restore', $this->standing))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('replicate', $this->standing))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('reorder', Expense::class))->toBeTrue()
        ->and(ExpenseResource::canEdit($this->standing))->toBeFalse()
        ->and(ExpenseResource::canDelete($this->standing))->toBeFalse()
        ->and(ExpenseResource::canDeleteAny())->toBeFalse();
});

it('refuses a super admin every edit and delete ability on an expense', function () {
    $superAdmin = ($this->roleUser)('super_admin');

    foreach (['update', 'delete', 'forceDelete', 'restore', 'replicate'] as $ability) {
        expect(Gate::forUser($superAdmin)->denies($ability, $this->standing))->toBeTrue("{$ability} must be refused to a super admin");
    }

    foreach (['deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'] as $ability) {
        expect(Gate::forUser($superAdmin)->denies($ability, Expense::class))->toBeTrue("{$ability} must be refused to a super admin");
    }
});

/*
|--------------------------------------------------------------------------
| Categories: managed without a developer, deactivated, never deleted
|--------------------------------------------------------------------------
*/

it('lists categories with their state, and filters by it', function () {
    $inactive = ExpenseCategory::factory()->inactive()->create(['name_en' => 'Retired heading']);

    Livewire::actingAs(($this->actorWith)('view_any_expense_category'))
        ->test(ListExpenseCategories::class)
        ->assertCanSeeTableRecords([$this->category, $inactive])
        ->assertTableColumnStateSet('is_active', true, $this->category)
        ->assertTableColumnStateSet('is_active', false, $inactive)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$this->category])
        ->assertCanNotSeeTableRecords([$inactive])
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$this->category]);
});

it('adds a category through the form without a developer', function () {
    $actor = ($this->categoryActorWith)('create_expense_category');

    Livewire::actingAs($actor)
        ->test(CreateExpenseCategory::class)
        ->fillForm(['name_en' => 'Insurance', 'name_ar' => ''])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = ExpenseCategory::query()->where('name_en', 'Insurance')->sole();

    expect($created->is_active)->toBeTrue()
        ->and($created->name_ar)->toBeNull();

    $entry = Activity::query()->where('subject_type', ExpenseCategory::class)->where('subject_id', $created->getKey())->sole();

    expect((int) $entry->causer_id)->toBe((int) $actor->getKey());
});

it('refuses a duplicate or blank category name in the form', function () {
    Livewire::actingAs(($this->categoryActorWith)('create_expense_category'))
        ->test(CreateExpenseCategory::class)
        ->fillForm(['name_en' => 'Rent'])
        ->call('create')
        ->assertHasFormErrors(['name_en'])
        ->fillForm(['name_en' => ''])
        ->call('create')
        ->assertHasFormErrors(['name_en' => 'required']);

    expect(ExpenseCategory::query()->count())->toBe(1);
});

it('shows a lost race on a category name as a notification, not an error page', function () {
    $actor = ($this->categoryActorWith)('create_expense_category');

    Event::listen('eloquent.creating: '.ExpenseCategory::class, function (): void {
        DB::table('expense_categories')->insert([
            'name_en' => 'Insurance',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        Livewire::actingAs($actor)
            ->test(CreateExpenseCategory::class)
            ->fillForm(['name_en' => 'Insurance'])
            ->call('create')
            ->assertNotified();
    } finally {
        Event::forget('eloquent.creating: '.ExpenseCategory::class);
    }

    // The refusal halts the page and rolls its transaction back. In production the
    // competitor committed on another connection and its row stands; here it shared
    // this transaction, so what is left is the fixture's own "Rent", and nothing
    // half-written by the loser.
    expect(ExpenseCategory::query()->pluck('name_en')->all())->toBe(['Rent']);
});

it('does not offer the active switch on a new category, which is always active', function () {
    Livewire::actingAs(($this->categoryActorWith)('create_expense_category'))
        ->test(CreateExpenseCategory::class)
        ->assertFormFieldIsHidden('is_active');
});

it('renames and deactivates a category through the edit form, and an expense keeps it', function () {
    $actor = ($this->categoryActorWith)('update_expense_category');

    Livewire::actingAs($actor)
        ->test(EditExpenseCategory::class, ['record' => $this->category->getKey()])
        ->fillForm(['name_en' => 'Premises rent', 'name_ar' => 'إيجار', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = ExpenseCategory::query()->findOrFail($this->category->getKey());

    expect($stored->name_en)->toBe('Premises rent')
        ->and($stored->name_ar)->toBe('إيجار')
        ->and($stored->is_active)->toBeFalse()
        ->and(Expense::query()->findOrFail($this->standing->getKey())->expense_category_id)->toBe((int) $this->category->getKey());
});

it('refuses to edit a category for an actor holding everything but update_expense_category', function () {
    Livewire::actingAs(($this->actorWithAllBut)('update_expense_category'))
        ->test(EditExpenseCategory::class, ['record' => $this->category->getKey()])
        ->assertForbidden();

    Livewire::actingAs(($this->actorWithAllBut)('update_expense_category'))
        ->test(ListExpenseCategories::class)
        ->assertTableActionHidden('edit', $this->category);

    expect(ExpenseCategory::query()->findOrFail($this->category->getKey())->name_en)->toBe('Rent');
});

it('refuses to delete a category, even to a super admin, by any route', function () {
    $this->actingAs(($this->roleUser)('super_admin'));

    expect(array_keys(ExpenseCategoryResource::getPages()))->toBe(['index', 'create', 'edit']);

    // No view or delete route exists for a category: the record URL itself is a 404,
    // and a DELETE on the one record route there is (edit) is refused as a bad verb.
    $this->delete("/admin/expense-categories/{$this->category->getKey()}")->assertNotFound();
    $this->delete("/admin/expense-categories/{$this->category->getKey()}/edit")->assertStatus(405);
    $this->get("/admin/expense-categories/{$this->category->getKey()}/delete")->assertNotFound();

    $superAdmin = ($this->roleUser)('super_admin');

    foreach (['delete', 'forceDelete', 'restore', 'replicate'] as $ability) {
        expect(Gate::forUser($superAdmin)->denies($ability, $this->category))->toBeTrue("{$ability} must be refused to a super admin");
    }

    foreach (['deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'] as $ability) {
        expect(Gate::forUser($superAdmin)->denies($ability, ExpenseCategory::class))->toBeTrue("{$ability} must be refused to a super admin");
    }

    expect(ExpenseCategoryResource::canDelete($this->category))->toBeFalse()
        ->and(ExpenseCategoryResource::canDeleteAny())->toBeFalse();
});

it('refuses a category delete even to an actor granted the ability that is never seeded', function () {
    // The name does not exist in the seeded set; creating it here and granting it
    // is the strongest version of "the policy refuses anyway".
    Permission::findOrCreate('delete_expense_category', 'web');
    Permission::findOrCreate('delete_any_expense_category', 'web');

    $actor = ($this->actorWith)(
        ...EXPENSE_RESOURCE_PERMISSIONS,
        ...['delete_expense_category', 'delete_any_expense_category'],
    );

    $this->actingAs($actor);

    expect($actor->can('delete_expense_category'))->toBeTrue('The control: the actor really holds the ability.')
        ->and(Gate::forUser($actor)->denies('delete', $this->category))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('deleteAny', ExpenseCategory::class))->toBeTrue()
        ->and(ExpenseCategoryResource::canDelete($this->category))->toBeFalse()
        ->and(ExpenseCategoryResource::canDeleteAny())->toBeFalse();

    $list = Livewire::actingAs($actor)->test(ListExpenseCategories::class);

    $list->assertTableActionDoesNotExist('delete', null, $this->category);

    try {
        $list->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $this->category->getKey()]);
    } catch (Throwable) {
        // Refusing an unknown action outright is also a refusal.
    }

    expect(ExpenseCategory::query()->whereKey($this->category->getKey())->exists())->toBeTrue();
});

it('offers no delete or bulk action on any category page, to an admin or a super admin', function (string $role) {
    $actor = ($this->roleUser)($role);

    $list = Livewire::actingAs($actor)->test(ListExpenseCategories::class);

    $list->assertTableActionDoesNotExist('delete', null, $this->category)
        ->assertTableActionDoesNotExist('forceDelete', null, $this->category)
        ->assertTableActionDoesNotExist('restore', null, $this->category)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    try {
        $list->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $this->category->getKey()]);
    } catch (Throwable) {
        // Refusing an unknown action outright is also a refusal.
    }

    Livewire::actingAs($actor)
        ->test(EditExpenseCategory::class, ['record' => $this->category->getKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');

    expect(ExpenseCategory::query()->whereKey($this->category->getKey())->exists())->toBeTrue();
})->with(['admin', 'super_admin']);

it('keeps a category that expenses name, whatever else is tried', function () {
    // The database backstop beneath the missing delete path.
    expect(fn () => $this->category->delete())->toThrow(QueryException::class)
        ->and(ExpenseCategory::query()->whereKey($this->category->getKey())->exists())->toBeTrue();
});
