<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Exceptions\PaymentAlreadyReversedException;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Rules\NotACardNumber;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| NotACardNumber and PaymentResource (P2-T04, unit 5)
|--------------------------------------------------------------------------
|
| Two surfaces in one file because the plan's unit 5 is: the validation rule
| that keeps a PAN out of a free-text terminal reference, and the
| read-plus-one-action resource that lists payments and lets a super admin
| reverse one. PaymentReversalTest (unit 3) already covers ReversePaymentAction
| itself — every authorization branch, all three reversal columns, the
| idempotence refusal, the activity log causer — so none of that is repeated
| here. This file proves the RULE'S shape, and the PANEL WIRING between a
| click and that Action, the same split ChargeResourceTest draws against
| AdjustChargeTest / WriteOffChargeTest.
|
| PaymentAuthorizationTest (unit 4) proves Gate::getPolicyFor(Payment::class)
| resolves PaymentPolicy with no Gate::policy() line in AppServiceProvider,
| and says explicitly that the panel half of that discovery proof belongs
| here, once PaymentResource exists. The staff-denial test below, near the
| bottom of this file, is that other half.
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

    // readFor('payment') in RolePermissionSeeder: view_any_payment and
    // view_payment; create_payment is granted separately (FINANCE_WRITE).
    // reverse_payment is not granted to admin at all.
    $this->admin = ($this->actorWith)('admin');

    // Holds nothing financial at all — not even a read permission. Same as
    // ChargeResourceTest and PaymentAuthorizationTest.
    $this->staff = ($this->actorWith)('staff');

    /**
     * A payment recorded with two tenders and one allocation — 300 on card
     * and 700 in cash against a fresh 1,000 bill — built directly on the
     * models rather than through RecordPaymentAction, because this file is
     * about the RESOURCE reading these rows, not about recording them
     * (RecordPaymentTest and PaymentIdempotencyTest already cover that
     * Action). Same split-tender shape PaymentReversalTest's
     * recordSplitPayment() uses, for the same reason: a resource that only
     * ever saw a single-tender fixture could not prove the total column
     * sums rather than reads a stored amount that does not exist.
     *
     * @return array{0: Charge, 1: Payment}
     */
    $this->recordSplitPayment = function (): array {
        $charge = Charge::factory()->create(['amount' => '1000.000']);

        $payment = Payment::factory()->create(['recorded_by' => $this->admin->getKey()]);

        PaymentTender::factory()->card()->create([
            'payment_id' => $payment->getKey(),
            'amount' => '300.000',
        ]);

        PaymentTender::factory()->create([
            'payment_id' => $payment->getKey(),
            'amount' => '700.000',
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => '1000.000',
        ]);

        return [$charge, $payment->refresh()];
    };
});

/*
|--------------------------------------------------------------------------
| NotACardNumber — rejects PAN-shaped input, and nothing else
|--------------------------------------------------------------------------
|
| Design section 5: card numbers, PINs and CVVs are never stored anywhere in
| this system. The rule is a targeted guard against one shape — 13 to 19
| digits once every separator is stripped — not a general ban on digits,
| so both directions of both boundaries get their own sample below. A
| dataset where every case fails on the same clause would leave the other
| clause untested, and this project has shipped exactly that defect before
| (docs/ENGINEERING.md, "test the surface, not the instance you just
| fixed").
*/

it('rejects PAN-shaped input however its digits are grouped, with the translated message', function (string $value) {
    $validator = Validator::make(
        ['external_reference' => $value],
        ['external_reference' => [new NotACardNumber]],
    );

    expect($validator->fails())->toBeTrue()
        // The translated string, not a hardcoded sentence — a rule that
        // failed this by returning English literally would still read
        // correctly in this English-only suite, but would not go through
        // lang/en/payments.php, which is the property this line is for.
        ->and($validator->errors()->first('external_reference'))->toBe(__('payments.not_a_card_number'));
})->with([
    '16 digits, unbroken' => ['4111111111111111'],
    '16 digits, space-separated' => ['4111 1111 1111 1111'],
    '16 digits, dash-separated' => ['4111-1111-1111-1111'],
    // Generated to an exact length rather than typed out and counted by
    // eye — the boundary is the whole point of these two cases.
    '13 digits — the short PAN boundary' => [str_repeat('4', 13)],
    '19 digits — the long PAN boundary' => [str_repeat('4', 19)],

    /*
     * THE SEPARATORS THAT WALKED PAST THE FIRST VERSION OF THIS RULE.
     *
     * It stripped `' '` and `'-'` and nothing else, so each of these was
     * accepted — measured on this branch during cross-review, not imagined.
     * Every one is what a real paste produces: a tab out of a spreadsheet, a
     * non-breaking space out of formatted text, and an en dash a word
     * processor substituted for a typed hyphen.
     *
     * Written as escapes and codepoints rather than pasted glyphs, so the
     * characters under test survive the file being re-encoded and stay legible
     * to whoever reads this next.
     */
    '16 digits, tab-separated' => ["4111\t1111\t1111\t1111"],
    '16 digits, non-breaking spaces' => ["4111\u{00A0}1111\u{00A0}1111\u{00A0}1111"],
    '16 digits, en dashes' => ["4111\u{2013}1111\u{2013}1111\u{2013}1111"],
    '16 digits, mixed separators' => ["4111 1111\u{00A0}1111\t1111"],
]);

it('passes ordinary terminal references, including both boundaries just outside the PAN range', function (string $value) {
    $validator = Validator::make(
        ['external_reference' => $value],
        ['external_reference' => [new NotACardNumber]],
    );

    expect($validator->passes())->toBeTrue();
})->with([
    // The boundaries in both directions — see the must-catch dataset above.
    // A rule that fired on either of these would refuse a perfectly
    // ordinary reference and get deleted by whoever it interrupted.
    '12 digits — one short of the range' => [str_repeat('4', 12)],
    '20 digits — one over the range' => [str_repeat('4', 20)],
    // A letter anywhere makes this not PAN-shaped at all, whatever the
    // digit run looks like.
    'letters mixed with digits' => ['AUTH-1234567890'],
    'a short alphanumeric reference' => ['REF 0001'],
    'a bare word' => ['approved'],
    'blank' => [''],
]);

/*
|--------------------------------------------------------------------------
| PaymentResource — read-plus-one-action, driven through the real component
|--------------------------------------------------------------------------
|
| ChargeResourceTest is the model this follows: assertions against the ROWS
| a component actually returns, not against whether a query merely ran, and
| a crafted-mount or visibility probe rather than "the button is hidden" —
| see that file's own header for why the second one proves nothing on its
| own without a super-admin control.
*/

it('lets an admin list payments and see a recorded one', function () {
    [, $payment] = ($this->recordSplitPayment)();

    Livewire::actingAs($this->admin)
        ->test(ListPayments::class)
        ->assertCanSeeTableRecords([$payment]);
});

/*
|--------------------------------------------------------------------------
| The tender total is a SQL sum, never a PHP one
|--------------------------------------------------------------------------
*/

it('renders the tender total as a SQL sum, not a PHP one — 300 plus 700 is 1,000', function () {
    // 300 and 700 land in the fixture as decimal(12,3) STRINGS
    // (PaymentTenderFactory). Summing them in PHP would silently cross a
    // float boundary — see PaymentResource's own docblock and
    // ChargeBalance's — so the assertion below is against the literal the
    // database's SUM() produces, never a total re-added in this test from
    // the same two rows.
    [, $payment] = ($this->recordSplitPayment)();

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableColumnStateSet('tenders_sum_amount', '1000.000', $payment);
});

/*
|--------------------------------------------------------------------------
| The reverse action — visible only to a super admin, and only once
|--------------------------------------------------------------------------
*/

it('hides the reverse action from an admin and shows it to a super admin', function () {
    [, $payment] = ($this->recordSplitPayment)();

    Livewire::actingAs($this->admin)
        ->test(ListPayments::class)
        ->assertTableActionHidden('reverse', $payment);

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionVisible('reverse', $payment);
});

it('actually reverses a payment through the table action, setting all three reversal columns', function () {
    // The behavioural proof — mount the real component, fill the reason,
    // call it, and read the row back. A policy assertion alone would not
    // prove the panel wiring between a click and ReversePaymentAction
    // actually runs.
    [, $payment] = ($this->recordSplitPayment)();

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->callTableAction('reverse', $payment, [
            'reason' => 'Recorded against the wrong bill.',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('payments.reversed_successfully'));

    $stored = DB::table('payments')->where('id', $payment->getKey())->first();

    expect($stored->reversed_at)->not->toBeNull()
        ->and((int) $stored->reversed_by)->toBe((int) $this->superAdmin->getKey())
        ->and($stored->reversal_reason)->toBe('Recorded against the wrong bill.');
});

it('hides the reverse action once a payment is already reversed, and the Action itself still refuses a second one', function () {
    // Two assertions, deliberately, because the visibility rule is a UX
    // courtesy and the exception is the guard — see PaymentResource's own
    // docblock on reverseAction(). Removing ->visible() would not open a
    // security hole; removing ->authorize() would.
    $payment = Payment::factory()->reversed($this->superAdmin)->create();

    Livewire::actingAs($this->superAdmin)
        ->test(ListPayments::class)
        ->assertTableActionHidden('reverse', $payment);

    $thrown = null;

    try {
        app(ReversePaymentAction::class)->execute(
            $this->superAdmin,
            (int) $payment->getKey(),
            'A second attempt at an already-reversed payment.',
        );
    } catch (PaymentAlreadyReversedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(PaymentAlreadyReversedException::class);
});

/*
|--------------------------------------------------------------------------
| The panel half of unit 4's discovery proof
|--------------------------------------------------------------------------
|
| PaymentAuthorizationTest's "lets Laravel discover PaymentPolicy through
| convention, with no explicit registration" proves Gate::getPolicyFor()
| resolves PaymentPolicy with no Gate::policy() line in AppServiceProvider.
| That test's own docblock says the panel half belongs here, once
| PaymentResource exists: an actor without view_any_payment refused the list
| page through the REAL component, so the policy discovery resolved is
| provably the one enforcing the panel rather than something else standing
| in for it.
*/

it('denies staff every surface of the payments resource, who hold nothing financial at all', function () {
    [, $payment] = ($this->recordSplitPayment)();

    expect($this->staff->can('view_any_payment'))->toBeFalse();

    $this->actingAs($this->staff)->get('/admin/payments')->assertForbidden();
    $this->actingAs($this->staff)->get("/admin/payments/{$payment->getKey()}")->assertForbidden();

    Livewire::actingAs($this->staff)
        ->test(ListPayments::class)
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The view page, and the query its total silently depends on
|--------------------------------------------------------------------------
|
| Added after the independent pre-PR review found ViewPayment driven by
| nothing but the staff 403 above. The page worked — that was checked by
| hand — but the claim holding it up was protected by no test at all.
|
| ViewPayment's docblock says the `tenders_sum_amount` alias is present
| because Filament resolves a ViewRecord's route binding through the
| resource's own getEloquentQuery(), which is where the withSum() lives.
| That is a claim about somebody else's framework, and if it ever stops
| being true the total does not error — it renders as the empty placeholder,
| which reads as a styling bug rather than a missing figure on a receipt.
*/

it('renders a payment on its own page, with the tender total the list page shows', function () {
    [$charge, $payment] = ($this->recordSplitPayment)();

    $response = $this->actingAs($this->superAdmin)->get("/admin/payments/{$payment->getKey()}");

    $response->assertOk()
        ->assertSee($payment->reference)
        // The 300 + 700 total, formatted. A literal, not a figure recomputed
        // in the test from the same tender rows the page summed.
        ->assertSee(__('payments.amount_lyd', ['amount' => '1000.000']));

    /*
     * And the alias really is what produced it. If the route-binding query
     * ever stops going through getEloquentQuery(), this is null and the page
     * above quietly renders a placeholder instead of a total.
     */
    $record = PaymentResource::getEloquentQuery()
        ->whereKey($payment->getKey())
        ->first();

    expect($record?->tenders_sum_amount)->not->toBeNull(
        'PaymentResource::getEloquentQuery() no longer carries the tender-sum alias, so the view page has no total to render.',
    );
});

it('shows the reversal record on the view page once a payment has been reversed', function () {
    [, $payment] = ($this->recordSplitPayment)();

    app(ReversePaymentAction::class)->execute(
        $this->superAdmin,
        (int) $payment->getKey(),
        'Cash was miscounted at the desk.',
    );

    // The financial facts survive a reversal untouched, so the page must still
    // show them alongside the reason — a reversed receipt is still a record of
    // money that was recorded and then voided, not a blank.
    $this->actingAs($this->superAdmin)
        ->get("/admin/payments/{$payment->getKey()}")
        ->assertOk()
        ->assertSee($payment->reference)
        ->assertSee('Cash was miscounted at the desk.')
        ->assertSee(__('payments.amount_lyd', ['amount' => '1000.000']));
});
