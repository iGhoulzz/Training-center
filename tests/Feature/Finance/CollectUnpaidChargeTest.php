<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Collecting a payment on a bill later (P4-T32, QA Q1; later installments P4-T36)
|--------------------------------------------------------------------------
|
| The collection panel used to exist only in the page session that pressed
| Confirm, so a bill whose page was closed could never be collected from the
| app. The Charges table now offers "Collect payment" on any COLLECTABLE bill,
| and the Enrol & Collect page opens the panel for that bill from `?charge=`.
|
| "Collectable" is ONE predicate, in ChargeQueryService: not written off and
| something outstanding. A payment already on the bill does not block it (owner,
| 2026-10-29: 300 of 850 today, the remaining 550 later, as a new payment and a
| new receipt on the same bill); a reversed payment never counted. The button
| reads that predicate from the table query, and the page re-checks it on the
| server, so a URL is never trusted.
|
| EVERY ACTOR HERE HOLDS EXACTLY THE PERMISSIONS NAMED, NEVER A ROLE. A super
| admin holds every permission and would pass whatever ability the code checks.
| Each allowed case is paired with an actor holding one permission fewer.
*/
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title and status.
 *
 * Reading them takes them out of the session, so this is called once per test.
 * Filament's assertNotNotified() is not used for the "nothing else was sent"
 * checks: it runs after an earlier assert has already emptied the session.
 *
 * @return list<array{title: string, status: string}>
 */
function collectUnpaidSentNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (FilamentNotification $notification): array => [
            'title' => (string) $notification->getTitle(),
            'status' => (string) $notification->getStatus(),
        ])
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Queue::fake();

    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    // Everything the button and the page need: read the list, reach the page,
    // and record a payment.
    $this->collectorPermissions = ['view_any_charge', 'view_charge', 'create_enrollment', 'create_payment'];

    $this->collector = ($this->actor)($this->collectorPermissions);

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

    $this->collectPaymentAction = fn (Charge $charge): TestAction => TestAction::make('collectPayment')->table($charge);
});

/*
|--------------------------------------------------------------------------
| The button: which bills offer it, and to whom
|--------------------------------------------------------------------------
*/

it('offers Collect payment on a bill that has no payment, linking to the page with that bill in the URL', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge))
        ->assertActionHasUrl(($this->collectPaymentAction)($charge), url('/admin/enroll-and-collect?charge='.$charge->getKey()));
});

it('hides Collect payment on a bill that is paid in full', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '100.125');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

it('offers Collect payment on a bill whose payment is live but partial, for the later installment', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge))
        ->assertActionHasUrl(($this->collectPaymentAction)($charge), url('/admin/enroll-and-collect?charge='.$charge->getKey()));
});

it('hides Collect payment on a bill that is written off', function () {
    $charge = Charge::factory()->writtenOff($this->collector)->create(['list_price' => '100.125']);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

it('hides Collect payment on a bill with nothing outstanding even though no payment was made', function () {
    // A bill discounted to nothing: amount 0.000, never paid, never owed.
    $charge = Charge::factory()->create(['list_price' => '0.000']);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

it('offers Collect payment again once the only payment on the bill is reversed', function () {
    // A reversed payment never counted toward what was paid, so the bill is still collectable.
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000', reversed: true);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
});

it('offers Collect payment when a live payment stands beside a reversed one', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000', reversed: true);
    ($this->payTowards)($charge, '25.000');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
});

it('hides Collect payment once later installments have paid the bill off', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000');
    ($this->payTowards)($charge, '60.125');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

it('hides Collect payment from an actor without create_payment, who holds every other permission it needs', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    $withoutPayment = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['create_payment'])));

    expect($withoutPayment->can('create_payment'))->toBeFalse()
        ->and($withoutPayment->can('create_enrollment'))->toBeTrue()
        ->and($withoutPayment->can('view_any_charge'))->toBeTrue();

    Livewire::actingAs($withoutPayment)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));

    // The positive control: the same list, the same bill, one permission more.
    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
});

it('hides Collect payment from an actor who could not open the page it links to', function () {
    // EnrollAndCollect::canAccess() asks for create_enrollment. A button
    // leading to a 403 is worse than no button.
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    $withoutEnrolling = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['create_enrollment'])));

    expect($withoutEnrolling->can('create_payment'))->toBeTrue()
        ->and($withoutEnrolling->can('create_enrollment'))->toBeFalse();

    Livewire::actingAs($withoutEnrolling)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

/*
|--------------------------------------------------------------------------
| The table's query count does not grow with its rows
|--------------------------------------------------------------------------
*/

it('reads the same number of tables whether the list shows one bill or five, the button included', function () {
    $this->collector->can('create_payment');
    $this->collector->can('view_any_charge');
    $this->collector->can('create_enrollment');

    $measure = function (): int {
        $statements = captureStatements();
        Livewire::actingAs($this->collector)->test(ListCharges::class);

        return collect($statements)->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select')
            && preg_match('/\b(?:from|join) `?(students|courses|batches|enrollments|charges|payments|payment_allocations)`?\b/', $statement['sql']) === 1
        )->count();
    };

    Charge::factory()->create(['list_price' => '100.000']);
    $withOneRow = $measure();

    // Four more, in every state the button decides on.
    $paid = Charge::factory()->create(['list_price' => '200.000']);
    ($this->payTowards)($paid, '200.000');
    $reversed = Charge::factory()->create(['list_price' => '300.000']);
    ($this->payTowards)($reversed, '50.000', reversed: true);
    Charge::factory()->writtenOff($this->collector)->create(['list_price' => '400.000']);
    Charge::factory()->create(['list_price' => '500.000']);
    $withFiveRows = $measure();

    expect($withOneRow)->toBeGreaterThan(0)
        ->and($withFiveRows)->toBe($withOneRow);
});

/*
|--------------------------------------------------------------------------
| The page: opening the panel for a bill from the URL
|--------------------------------------------------------------------------
*/

it('shows a bill opened by link on the Collect stage, with the earlier stages closed', function () {
    // P4-T29's step cards: the panel is the Collect stage, so the cards must say
    // so, and the selection stages stay closed while this bill is open.
    $charge = Charge::factory()->create(['list_price' => '100.125']);

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey())
        ->assertSet('flowStep', 4);

    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $current = (new DOMXPath($document))->query('//nav[@aria-label="'.__('collect.enrolment_steps').'"]//button[@aria-current="step"]');
    expect($current)->toHaveCount(1)
        ->and($current->item(0)->textContent)->toContain(__('collect.step_collect'));

    $page->call('goToStep', 1)->assertSet('flowStep', 4)
        ->call('advance')->assertSet('flowStep', 4);
});

it('opens the collection panel for that bill and records exactly one payment, allocation and tender with the exact amounts', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    Charge::factory()->create(['list_price' => '999.000']);

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey())
        ->assertFormSet(['amount' => '100.125'], 'collectForm')
        ->assertSee(__('collect.take_payment'));

    expect($page->get('collectionIdempotencyKey'))->toBeString()->not->toBeEmpty();

    $page->fillForm([
        'amount' => '40.125',
        'tenders' => [['method' => 'cash', 'amount' => '40.125', 'external_reference' => null]],
    ], 'collectForm')->call('finalize')->assertHasNoFormErrors([], 'collectForm');

    $allocation = PaymentAllocation::query()->sole();
    $tender = PaymentTender::query()->sole();

    expect(Payment::query()->count())->toBe(1)
        ->and($allocation->charge_id)->toBe($charge->getKey())
        ->and($allocation->amount)->toBe('40.125')
        ->and($tender->amount)->toBe('40.125')
        ->and($tender->method->value)->toBe('cash');
});

it('records the whole bill when the default amount is collected as it stands', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);

    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->call('finalize')
        ->assertHasNoFormErrors([], 'collectForm');

    expect(PaymentAllocation::query()->sole()->amount)->toBe('100.125')
        ->and(PaymentTender::query()->sole()->amount)->toBe('100.125');
});

it('opens the panel again for a bill whose only payment was reversed, for the full outstanding amount', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000', reversed: true);

    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey())
        ->assertFormSet(['amount' => '100.125'], 'collectForm');
});

it('refuses a link to a bill that cannot be collected: no panel, nothing written, the refusal shown', function (Closure $makeCharge) {
    $charge = $makeCharge($this);
    $before = Payment::query()->count();

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null)
        ->assertSet('collectionIdempotencyKey', null)
        ->assertDontSee(__('collect.take_payment'));

    // A crafted call straight at finalize() still has nothing to record against.
    $page->call('finalize');

    expect(Payment::query()->count())->toBe($before)
        ->and(collectUnpaidSentNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
})->with([
    'paid in full' => [function ($test) {
        $charge = Charge::factory()->create(['list_price' => '100.125']);
        ($test->payTowards)($charge, '100.125');

        return $charge;
    }],
    'written off' => [fn ($test) => Charge::factory()->writtenOff($test->collector)->create(['list_price' => '100.125'])],
    'written off after a part payment' => [function ($test) {
        $charge = Charge::factory()->writtenOff($test->collector)->create(['list_price' => '100.125']);
        ($test->payTowards)($charge, '40.000');

        return $charge;
    }],
    'nothing outstanding' => [fn ($test) => Charge::factory()->create(['list_price' => '0.000'])],
]);

it('refuses a link naming a bill that does not exist or is not a number', function (mixed $parameter) {
    Charge::factory()->create(['list_price' => '100.125']);

    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $parameter])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null)
        ->assertDontSee(__('collect.take_payment'));

    expect(Payment::query()->count())->toBe(0)
        ->and(collectUnpaidSentNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
})->with([
    'an id that is not there' => [987654321],
    'text' => ['abc'],
    'zero' => ['0'],
    'a negative id' => ['-4'],
    'a list' => [['1', '2']],
]);

it('refuses an actor without create_payment the same way, even for a collectable bill', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    $withoutPayment = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['create_payment'])));

    expect($withoutPayment->can('create_enrollment'))->toBeTrue()
        ->and($withoutPayment->can('create_payment'))->toBeFalse();

    Livewire::actingAs($withoutPayment)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null)
        ->assertSet('collectionIdempotencyKey', null)
        ->assertDontSee(__('collect.take_payment'))
        ->call('finalize');

    expect(Payment::query()->count())->toBe(0)
        ->and(collectUnpaidSentNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);

    // The positive control: one permission more opens the panel for the same bill.
    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey());
});

it('opens no panel and shows no refusal when the page is opened without a charge in the URL', function () {
    Charge::factory()->create(['list_price' => '100.125']);

    Livewire::actingAs($this->collector)
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null)
        ->assertDontSee(__('collect.take_payment'));

    expect(collectUnpaidSentNotifications())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The bill is re-checked when the payment is submitted (P4-T32 review)
|--------------------------------------------------------------------------
|
| The panel is opened for a collectable bill, but the bill can
| change before the button is pressed, and the panel's bill id is a client-side
| property. finalize() therefore checks the same predicate again before writing.
| Its own payment is the one exception, so a double-click still replays.
*/

it('refuses the second of two tabs opened on the same link once the first has paid the bill in full', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $open = fn (string $amount) => Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => $amount, 'tenders' => [['method' => 'cash', 'amount' => $amount, 'external_reference' => null]]], 'collectForm');

    $first = $open('100.000');
    $second = $open('40.000');

    $first->call('finalize')->assertNotified(__('collect.collected_successfully'));
    $second->call('finalize')->assertNotified(__('collect.charge_not_collectable'));

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['100.000'])
        ->and(Payment::query()->count())->toBe(1);
});

it('lets the second of two tabs record its installment when the first only paid part and the sum fits', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $open = fn (string $amount) => Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => $amount, 'tenders' => [['method' => 'cash', 'amount' => $amount, 'external_reference' => null]]], 'collectForm');

    $first = $open('40.000');
    $second = $open('60.000');

    $first->call('finalize')->assertNotified(__('collect.collected_successfully'));
    $second->call('finalize')->assertNotified(__('collect.collected_successfully'));

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())->toBe(['40.000', '60.000'])
        ->and(Payment::query()->count())->toBe(2);
});

it('refuses to collect a bill that was written off after its panel opened', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey());
    collectUnpaidSentNotifications();

    $charge->forceFill(['written_off_at' => now(), 'written_off_by' => $this->collector->getKey(), 'written_off_reason' => 'SENTINEL-WRITE-OFF'])->save();

    $page->call('finalize');

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(collectUnpaidSentNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
});

it('refuses a browser rewrite of the bill id, so the panel never reaches another bill', function (Closure $makeTarget) {
    $opened = Charge::factory()->create(['list_price' => '100.000']);
    $target = $makeTarget($this);
    $before = PaymentAllocation::query()->where('charge_id', $target->getKey())->count();

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $opened->getKey()])
        ->test(EnrollAndCollect::class);
    collectUnpaidSentNotifications();

    // Server-owned since the P4-T32 review (#[Locked]): the rewrite itself is refused.
    expect(fn () => $page->set('collectionChargeId', $target->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    $page->assertSet('collectionChargeId', $opened->getKey());

    expect(PaymentAllocation::query()->where('charge_id', $target->getKey())->count())->toBe($before)
        ->and(PaymentAllocation::query()->where('charge_id', $opened->getKey())->count())->toBe(0);
})->with([
    'a bill paid in full' => [function ($test): Charge {
        $charge = Charge::factory()->create(['list_price' => '100.000']);
        ($test->payTowards)($charge, '100.000');

        return $charge;
    }],
    'a written-off bill' => [fn ($test): Charge => tap(Charge::factory()->create(['list_price' => '100.000']), fn (Charge $charge) => $charge->forceFill(['written_off_at' => now(), 'written_off_by' => $test->collector->getKey(), 'written_off_reason' => 'SENTINEL'])->save())],
]);

it('still records one payment for a double-clicked submit, because the panel recognises its own payment', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => '40.000', 'tenders' => [['method' => 'cash', 'amount' => '40.000', 'external_reference' => null]]], 'collectForm');
    collectUnpaidSentNotifications();

    $page->call('finalize')->call('finalize');

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->sole()->amount)->toBe('40.000')
        ->and(array_column(collectUnpaidSentNotifications(), 'title'))->not->toContain(__('collect.charge_not_collectable'));
});

it('refuses a link from an actor who may not view that bill, and hides the button from them', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $withoutView = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['view_charge'])));

    Livewire::actingAs($withoutView)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null)
        ->assertNotified(__('collect.charge_not_collectable'));

    Livewire::actingAs($withoutView)->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));

    Livewire::actingAs($this->collector)->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
});

it('gives an actor without create_payment no way to plant a bill in the panel', function () {
    // Was "a 403 even when the rewritten bill could not be collected": finalize()
    // authorizes before it checks the bill. The bill id is server-owned now, so the
    // rewrite itself is refused and the panel never holds a bill to check.
    $partPaid = Charge::factory()->create(['list_price' => '100.000']);
    ($this->payTowards)($partPaid, '30.000');
    $withoutPayment = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['create_payment'])));

    $page = Livewire::actingAs($withoutPayment)->test(EnrollAndCollect::class);

    expect(fn () => $page->set('collectionChargeId', $partPaid->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    $page->assertSet('collectionChargeId', null);

    expect(PaymentAllocation::query()->where('charge_id', $partPaid->getKey())->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);
});

it('keeps a valid unpaid bill out of reach of an actor who may collect but not view charges', function () {
    // Codex review of P4-T32: an actor with create_enrollment and create_payment but
    // not view_charge opens the page with no bill, then tries to plant the id of a
    // bill that is perfectly collectable. The id is server-owned, so neither the
    // bill's details nor its payment are reachable.
    $unpaid = Charge::factory()->create(['list_price' => '100.000']);
    $unpaid->enrollment->student->forceFill(['first_name' => 'SENTINEL-HIDDEN', 'last_name' => 'BILL'])->save();
    $withoutView = ($this->actor)(array_values(array_diff($this->collectorPermissions, ['view_charge', 'view_any_charge'])));

    $page = Livewire::actingAs($withoutView)->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null);

    expect(fn () => $page->set('collectionChargeId', $unpaid->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $page->set('collectionIdempotencyKey', (string) str()->uuid()))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $page->assertSet('collectionChargeId', null)
        ->assertDontSee('SENTINEL-HIDDEN');

    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->where('charge_id', $unpaid->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Later installments (P4-T36, owner request 2026-10-29)
|--------------------------------------------------------------------------
|
| A student pays 300 of an 850 bill today and the remaining 550 later. Staff open
| the bill from Charges, press "Collect payment", and the same panel opens,
| pre-filled with what is still owed; the result is a NEW payment and a NEW
| receipt on the SAME bill. Every figure below is written by hand.
*/

/** The bill aside's text for one figure, by its data attribute, or null if the row is not there. */
function billAsideFigure(string $html, string $attribute): ?string
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $row = (new DOMXPath($document))->query('//aside//div[@'.$attribute.']//dd');

    return $row->length === 0 ? null : trim(preg_replace('/\s+/u', ' ', $row->item(0)->textContent));
}

it('collects an 850 bill in two installments from Charges: 300 now and the remaining 550 later, one receipt each', function () {
    $charge = Charge::factory()->create(['list_price' => '850.000']);
    $studentId = (int) $charge->enrollment->student_id;

    expect(app(StudentBalanceQuery::class)->forStudent($studentId)->total->toDecimal())->toBe('850.000');

    // Today: 300 of 850.
    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertFormSet(['amount' => '850.000'], 'collectForm')
        ->fillForm(['amount' => '300.000', 'tenders' => [['method' => 'cash', 'amount' => '300.000', 'external_reference' => null]]], 'collectForm')
        ->call('finalize')
        ->assertNotified(__('collect.collected_successfully'));

    $firstReceipt = PaymentReceiptSnapshot::query()->sole();
    expect($firstReceipt->amount_paid)->toBe('300.000')
        ->and($firstReceipt->remaining_balance)->toBe('550.000')
        ->and(app(StudentBalanceQuery::class)->forStudent($studentId)->total->toDecimal())->toBe('550.000');

    // Later: the bill is still on the Charges list with its button, linking to the panel.
    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge))
        ->assertActionHasUrl(($this->collectPaymentAction)($charge), url('/admin/enroll-and-collect?charge='.$charge->getKey()));

    // The same panel, pre-filled with what is still owed and showing the earlier 300.
    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey())
        ->assertFormSet(['amount' => '550.000'], 'collectForm');

    // The single cash tender line starts at the same remaining amount.
    expect(collect($page->get('collect.tenders'))->pluck('amount')->values()->all())->toBe(['550.000'])
        ->and(billAsideFigure($page->html(), 'data-bill-paid'))->toBe('300.000 LYD')
        ->and(billAsideFigure($page->html(), 'data-bill-remaining'))->toBe('550.000 LYD');

    $page->call('finalize')->assertNotified(__('collect.collected_successfully'));

    // Two payments and two receipts on one bill; nothing is owed and the button has gone.
    $snapshots = PaymentReceiptSnapshot::query()->orderBy('payment_id')->get();
    expect(Payment::query()->count())->toBe(2)
        ->and($snapshots)->toHaveCount(2)
        ->and($snapshots->pluck('payment_reference')->unique()->all())->toHaveCount(2)
        ->and($snapshots->pluck('charge_reference')->unique()->all())->toBe([$charge->reference])
        ->and($snapshots->pluck('amount_paid')->all())->toBe(['300.000', '550.000'])
        ->and($snapshots->pluck('remaining_balance')->all())->toBe(['550.000', '0.000'])
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())->toBe(['300.000', '550.000'])
        ->and(PaymentTender::query()->orderBy('id')->pluck('amount')->all())->toBe(['300.000', '550.000'])
        ->and(app(StudentBalanceQuery::class)->forStudent($studentId)->total->toDecimal())->toBe('0.000');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));

    // And the link no longer opens a panel for the settled bill.
    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', null);
});

it('takes a third installment: 300, then 250, then the remaining 300', function () {
    $charge = Charge::factory()->create(['list_price' => '850.000']);

    foreach ([['300.000', '550.000'], ['250.000', '300.000'], ['300.000', '0.000']] as [$amount, $remaining]) {
        Livewire::actingAs($this->collector)
            ->withQueryParams(['charge' => $charge->getKey()])
            ->test(EnrollAndCollect::class)
            ->fillForm(['amount' => $amount, 'tenders' => [['method' => 'cash', 'amount' => $amount, 'external_reference' => null]]], 'collectForm')
            ->call('finalize')
            ->assertNotified(__('collect.collected_successfully'));

        expect(PaymentReceiptSnapshot::query()->orderByDesc('payment_id')->first()->remaining_balance)->toBe($remaining);
    }

    expect(Payment::query()->count())->toBe(3)
        ->and(PaymentReceiptSnapshot::query()->count())->toBe(3)
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())->toBe(['300.000', '250.000', '300.000']);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
});

it('refuses a later installment above what the bill still owes, and writes nothing', function () {
    $charge = Charge::factory()->create(['list_price' => '850.000']);
    ($this->payTowards)($charge, '300.000');

    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => '550.001', 'tenders' => [['method' => 'cash', 'amount' => '550.001', 'external_reference' => null]]], 'collectForm')
        ->call('finalize')
        ->assertNotified(__('payments.exceeds_outstanding'));

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['300.000'])
        ->and(PaymentTender::query()->count())->toBe(0)
        ->and(PaymentReceiptSnapshot::query()->count())->toBe(0);
});

it('shows the bill total, what is paid so far and what remains on the panel, and none of it on the enrolment preview', function () {
    $charge = Charge::factory()->create(['list_price' => '850.000']);

    // First, before any query string is set on this test's Livewire session.
    $enrolment = Livewire::actingAs($this->collector)->test(EnrollAndCollect::class);
    expect(billAsideFigure($enrolment->html(), 'data-bill-paid'))->toBeNull()
        ->and(billAsideFigure($enrolment->html(), 'data-bill-remaining'))->toBeNull();

    $fresh = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class);
    expect(billAsideFigure($fresh->html(), 'data-bill-paid'))->toBe('0.000 LYD')
        ->and(billAsideFigure($fresh->html(), 'data-bill-remaining'))->toBe('850.000 LYD');

    ($this->payTowards)($charge, '300.000');
    ($this->payTowards)($charge, '120.000', reversed: true);

    $partPaid = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class);

    // A reversed payment is not "paid so far".
    expect(billAsideFigure($partPaid->html(), 'data-bill-paid'))->toBe('300.000 LYD')
        ->and(billAsideFigure($partPaid->html(), 'data-bill-remaining'))->toBe('550.000 LYD')
        ->and($partPaid->html())->toContain('850.000');
});

it('tells the operator a further payment is taken by opening the bill again from Charges', function () {
    $charge = Charge::factory()->create(['list_price' => '850.000']);

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => '300.000', 'tenders' => [['method' => 'cash', 'amount' => '300.000', 'external_reference' => null]]], 'collectForm')
        ->call('finalize')
        ->assertNotified(__('collect.collected_successfully'));

    // A different amount typed into the same panel is a conflict, not a second installment.
    $page->fillForm(['amount' => '550.000', 'tenders' => [['method' => 'cash', 'amount' => '550.000', 'external_reference' => null]]], 'collectForm')
        ->call('finalize')
        ->assertNotified('This collection has already been recorded, and nothing further was taken. To take a further payment on this bill, open the bill again from Charges.');

    expect(Payment::query()->count())->toBe(1)
        ->and(__('collect.first_collection_hint'))->toBe('A collection may be partial. A further payment on this bill is taken by opening the bill again from Charges.');
});

it('gives a later installment only to an actor holding create_payment, view_charge and create_enrollment', function (string $withheld) {
    $charge = Charge::factory()->create(['list_price' => '850.000']);
    ($this->payTowards)($charge, '300.000');
    $short = ($this->actor)(array_values(array_diff($this->collectorPermissions, [$withheld, ...($withheld === 'view_charge' ? ['view_any_charge'] : [])])));

    expect($short->can($withheld))->toBeFalse();

    // Short by one permission: no button, and no panel for the part-paid bill.
    if ($withheld !== 'create_enrollment') {
        Livewire::actingAs($short)
            ->withQueryParams(['charge' => $charge->getKey()])
            ->test(EnrollAndCollect::class)
            ->assertSet('collectionChargeId', null)
            ->call('finalize');
    }

    if ($withheld !== 'view_charge') {
        Livewire::actingAs($short)->test(ListCharges::class)
            ->assertActionHidden(($this->collectPaymentAction)($charge));
    }

    expect(Payment::query()->count())->toBe(1);

    // The positive control: the full set sees the button and opens the panel.
    Livewire::actingAs($this->collector)->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
    Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->assertSet('collectionChargeId', $charge->getKey())
        ->assertFormSet(['amount' => '550.000'], 'collectForm');
})->with(['create_payment', 'view_charge', 'create_enrollment']);
