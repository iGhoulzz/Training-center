<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ViewCharge;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| ChargeResource — read-plus-two-actions, driven through the real component
|--------------------------------------------------------------------------
|
| AdjustChargeTest and WriteOffChargeTest already cover the two Actions
| directly: every authorization branch, the allocated-total refusal, the
| idempotence refusal, and the activity log entry. This file does not
| repeat any of that. It proves the RESOURCE — the wiring that stands
| between an HTTP request and those Actions:
|
|   - a hidden button is backed by a server-side refusal, proved by a
|     crafted Livewire mount, not by asking whether it renders;
|   - no create or edit surface exists to route around, including no
|     registered CreateAction/EditAction instance under any name;
|   - the outstanding balance sorts and filters against the RIGHT ROWS,
|     not merely without a SQL error — ChargeBalance's own docblock warns
|     the expression does not compose into a bare having(), and this
|     resource's filter is the one place that composition actually has to
|     work in production;
|   - a write-off changes nothing about what is displayed, per design
|     section 4's "nothing is erased";
|   - a business-rule refusal (adjusting below the allocated total)
|     reaches the operator as a notification, never as an unhandled
|     exception.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->superAdmin = ($this->actorWith)('super_admin');

    // readFor('charge') in RolePermissionSeeder: view_any_charge and
    // view_charge, and nothing else finance-related.
    $this->admin = ($this->actorWith)('admin');

    // Nothing financial at all — not even a read permission.
    $this->staff = ($this->actorWith)('staff');

    /**
     * A payment that still stands (or has been reversed), allocating $amount
     * to $charge. Same construction ChargeBalanceTest and AdjustChargeTest
     * use, because ChargeBalance is the single definition the table's
     * outstanding column and filter read, and a test that built allocations a
     * different way would risk exercising a different path.
     */
    $this->payTowards = function (Charge $charge, string $amount, bool $reversed = false): Payment {
        $payment = $reversed
            ? Payment::factory()->reversed()->create()
            : Payment::factory()->create();

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);

        return $payment;
    };
});

/*
|--------------------------------------------------------------------------
| Reaching the resource at all
|--------------------------------------------------------------------------
*/

it('lets a super admin list and view charges', function () {
    $charge = Charge::factory()->create();

    $this->actingAs($this->superAdmin)->get('/admin/charges')->assertSuccessful();
    $this->actingAs($this->superAdmin)->get("/admin/charges/{$charge->getKey()}")->assertSuccessful();
});

it('lets an admin list and view charges too, holding only the seeded read permissions', function () {
    // The positive control for the refusal tests below: an admin who could
    // not reach the resource at all would make "cannot adjust" a trivial,
    // uninteresting claim.
    $charge = Charge::factory()->create();

    expect($this->admin->can('view_any_charge'))->toBeTrue()
        ->and($this->admin->can('view_charge'))->toBeTrue();

    $this->actingAs($this->admin)->get('/admin/charges')->assertSuccessful();
    $this->actingAs($this->admin)->get("/admin/charges/{$charge->getKey()}")->assertSuccessful();
});

it('denies staff every surface of the charges resource, who hold nothing financial at all', function () {
    $charge = Charge::factory()->create();

    expect($this->staff->can('view_any_charge'))->toBeFalse();

    $this->actingAs($this->staff)->get('/admin/charges')->assertForbidden();
    $this->actingAs($this->staff)->get("/admin/charges/{$charge->getKey()}")->assertForbidden();

    Livewire::actingAs($this->staff)
        ->test(ListCharges::class)
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| An admin cannot adjust or write off — a crafted mount, not a hidden button
|--------------------------------------------------------------------------
|
| "The button is hidden" proves nothing about a hand-built Livewire payload
| that never renders the button at all — see RoleResourceLivewireTest and
| docs/ENGINEERING.md. Every probe below first mounts the SAME action as the
| super admin and asserts the stack is not empty; without that control a
| refusal assertion proves nothing (mountAction() unmounts and returns null
| for an unresolvable name, a disabled action, and an unauthorized one alike,
| so "nothing happened" cannot tell a refusal from a typo).
*/

it('refuses an admin\'s crafted mount of the adjust action, holding every seeded charge read permission', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    expect($this->admin->can('view_any_charge'))->toBeTrue()
        ->and($this->admin->can('view_charge'))->toBeTrue()
        ->and($this->admin->can('adjust_charge'))->toBeFalse();

    $context = ['table' => true, 'recordKey' => (string) $charge->getKey()];

    $control = Livewire::actingAs($this->superAdmin)->test(ListCharges::class);
    $control->call('mountAction', 'adjust', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The adjust action did not resolve even for a super admin — the probe below would prove nothing.'
    );

    $component = Livewire::actingAs($this->admin)->test(ListCharges::class);
    $component->call('mountAction', 'adjust', [], $context);
    expect($component->get('mountedActions'))->toBeEmpty();

    // Call it anyway, the way a crafted client would after a failed mount.
    $component->call('callMountedAction');

    expect((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('1000.000');
});

it('refuses an admin\'s crafted mount of the write-off action, holding every seeded charge read permission', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    expect($this->admin->can('write_off_charge'))->toBeFalse();

    $context = ['table' => true, 'recordKey' => (string) $charge->getKey()];

    $control = Livewire::actingAs($this->superAdmin)->test(ListCharges::class);
    $control->call('mountAction', 'writeOff', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The writeOff action did not resolve even for a super admin — the probe below would prove nothing.'
    );

    $component = Livewire::actingAs($this->admin)->test(ListCharges::class);
    $component->call('mountAction', 'writeOff', [], $context);
    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(Charge::query()->find($charge->getKey())?->isWrittenOff())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No create page and no edit page, anywhere, under any name
|--------------------------------------------------------------------------
*/

it('registers no create or edit action anywhere on this resource', function () {
    // docs/ENGINEERING.md: ->url() on a CreateAction/EditAction does not
    // remove the server-side handler those classes keep mountable — only NOT
    // REGISTERING one closes the door. So the claim worth proving is not
    // "there is no button named create", it is "no instance of that class
    // exists on this resource at all", the same assertion
    // SecurityReviewRegressionTest makes for RoleResource's inline EditAction.
    $charge = Charge::factory()->create();

    $list = Livewire::actingAs($this->superAdmin)->test(ListCharges::class);

    expect($list->instance()->getCachedHeaderActions())->toBeEmpty()
        ->and($list->instance()->getAction('create'))->toBeNull()
        ->and($list->instance()->getAction('edit'))->toBeNull();

    $recordActions = collect($list->instance()->getTable()->getRecordActions());

    expect($recordActions->map(fn ($action) => $action->getName())->all())
        ->toBe(['adjust', 'writeOff'])
        ->and($recordActions->filter(
            fn ($action) => $action instanceof CreateAction || $action instanceof EditAction
        ))->toBeEmpty();

    // ViewCharge reuses the exact same two shared builders (see the class
    // docblock) — checked separately because it is a second surface a
    // CreateAction/EditAction could have been added to.
    $view = Livewire::actingAs($this->superAdmin)
        ->test(ViewCharge::class, ['record' => $charge->getKey()]);

    $headerActions = collect($view->instance()->getCachedHeaderActions());

    expect($headerActions->map(fn ($action) => $action->getName())->all())
        ->toBe(['adjust', 'writeOff'])
        ->and($headerActions->filter(
            fn ($action) => $action instanceof CreateAction || $action instanceof EditAction
        ))->toBeEmpty();

    expect(ChargeResource::canCreate())->toBeFalse();
});

it('has no create or edit route registered at all', function () {
    // Belt and braces with the action-instance assertion above: even a
    // resource with no registered CreateAction could still expose a create
    // route if getPages() named one. ChargeResource::getPages() registers
    // exactly 'index' and 'view'.
    expect(Route::has('filament.admin.resources.charges.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.charges.edit'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No bulk actions
|--------------------------------------------------------------------------
*/

it('registers no bulk actions on the charges table', function () {
    // ChargePolicy::deleteAny() (and every other *Any method) refuses
    // unconditionally (design section 10; docs/ENGINEERING.md's "Bulk
    // actions cannot be authorized per record") — a bulk control could not
    // express "unless this charge carries an allocation". This asserts the
    // table offers nothing that would consult it.
    $table = Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();

    $this->actingAs($this->superAdmin);
    expect(ChargeResource::canDeleteAny())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The outstanding column sorts against the right rows
|--------------------------------------------------------------------------
*/

it('sorts the table by outstanding balance, in both directions', function () {
    // The same fixture shape ChargeBalanceTest's own sort test uses: a
    // reversed payment is folded in specifically because it must not move a
    // row in the order, only a standing allocation may.
    $small = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($small, '900.000');       // 100.000 outstanding

    $large = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']); // 1000.000 outstanding

    $middle = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($middle, '500.000');      // 500.000 outstanding

    ($this->payTowards)($large, '999.000', reversed: true);

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->sortTable(ChargeBalance::OUTSTANDING_ALIAS)
        ->assertCanSeeTableRecords([$small, $middle, $large], inOrder: true)
        ->sortTable(ChargeBalance::OUTSTANDING_ALIAS, 'desc')
        ->assertCanSeeTableRecords([$large, $middle, $small], inOrder: true);
});

/*
|--------------------------------------------------------------------------
| The outstanding-only filter filters against the right rows
|--------------------------------------------------------------------------
*/

it('filters the table to only charges with something still outstanding', function () {
    // This is the test that exists to catch the defect this filter shipped
    // with once: ChargeResource::table() used to select the alias first and
    // call having() on its NAME — the shape ChargeBalanceTest proves works —
    // but that proof runs against a bare DB::table() query, never through
    // Filament's filter pipeline. HasFilters::applyFiltersToTableQuery()
    // wraps every filter's apply() in $query->where(fn ($query) => …), and
    // Builder::addNestedWhereQuery() merges only the nested builder's
    // `wheres` back — its `havings` are silently dropped. The having()
    // version emitted valid SQL with no HAVING clause and returned every
    // row, filtered or not; a test that only asserted the query ran would
    // have passed against it just as happily. ChargeResource::table() now
    // uses whereRaw, and what pins that shape here is that this assertion
    // checks the actual ROWS returned, not merely the absence of a "column
    // not found" error. Do not reconcile this comment back to the
    // having()-on-the-alias shape; that is the bug.
    $unpaid = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    $settled = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($settled, '1000.000');

    // A reversed payment reopens the bill — outstanding again, so it belongs
    // in the filtered set even though a payment was once recorded against it.
    $reopened = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($reopened, '1000.000', reversed: true);

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->filterTable('outstanding_only', true)
        ->assertCanSeeTableRecords([$unpaid, $reopened])
        ->assertCanNotSeeTableRecords([$settled]);
});

/*
|--------------------------------------------------------------------------
| A written-off charge still appears, with its amount intact
|--------------------------------------------------------------------------
*/

it('keeps a written-off charge visible in the list, with its amount unchanged', function () {
    // Design section 4: "Nothing is erased." ChargeBalance deliberately does
    // not subtract a write-off (ChargeBalanceTest proves that directly); this
    // is the resource-level companion — the row itself, and the figure on it,
    // must still be there to look at.
    $charge = Charge::factory()->writtenOff($this->superAdmin)->create([
        'list_price' => '620.000',
        'amount' => '620.000',
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->assertCanSeeTableRecords([$charge])
        ->assertTableColumnStateSet('amount', '620.000', $charge)
        ->assertTableColumnStateSet('written_off', true, $charge);

    // The detail page too — the same amount, not blanked or hidden.
    Livewire::actingAs($this->superAdmin)
        ->test(ViewCharge::class, ['record' => $charge->getKey()])
        ->assertSee(ChargeResource::formatMoney('620.000'));
});

/*
|--------------------------------------------------------------------------
| A successful adjust and a successful write-off, through the component
|--------------------------------------------------------------------------
*/

it('lets a super admin adjust a charge through the table action', function () {
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->callTableAction('adjust', $charge, [
            'amount' => '750.000',
            'reason' => 'The list price was mistyped at enrolment.',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('charges.adjusted_successfully'));

    expect((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('750.000');
});

it('lets a super admin write off a charge through the table action', function () {
    $charge = Charge::factory()->create(['amount' => '500.000']);

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->callTableAction('writeOff', $charge, [
            'reason' => 'The centre has accepted this debt will never be collected.',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('charges.written_off_successfully'));

    $stored = DB::table('charges')->where('id', $charge->getKey())->first();

    expect($stored->written_off_at)->not->toBeNull()
        ->and((int) $stored->written_off_by)->toBe((int) $this->superAdmin->getKey());
});

/*
|--------------------------------------------------------------------------
| A refused adjustment surfaces as a notification, not an exception
|--------------------------------------------------------------------------
*/

it('surfaces a refused adjustment below the allocated total as a notification, not an unhandled exception', function () {
    // ChargeAmountBelowAllocatedException is the one typed exception this
    // form's happy path can trigger, and ChargeResource::adjustAction()
    // catches exactly it and calls $action->halt(). If that catch were
    // missing or caught the wrong type, this Livewire call would itself
    // throw and fail the test — the absence of that failure is part of the
    // claim, alongside the notification actually being sent.
    $charge = Charge::factory()->create(['list_price' => '1000.000', 'amount' => '1000.000']);
    ($this->payTowards)($charge, '300.000');

    Livewire::actingAs($this->superAdmin)
        ->test(ListCharges::class)
        ->callTableAction('adjust', $charge, [
            'amount' => '299.999',
            'reason' => 'Attempting to drop one dirham below what has been paid.',
        ])
        ->assertNotified(__('charges.amount_below_allocated'));

    // Not half-applied — the amount is exactly where it started.
    expect((string) DB::table('charges')->where('id', $charge->getKey())->value('amount'))
        ->toBe('1000.000');
});
