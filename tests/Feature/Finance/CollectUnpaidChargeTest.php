<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Collecting the first payment on a bill later (P4-T32, QA Q1)
|--------------------------------------------------------------------------
|
| The collection panel used to exist only in the page session that pressed
| Confirm, so a bill whose page was closed could never be collected from the
| app. The Charges table now offers "Collect payment" on a bill AWAITING ITS
| FIRST PAYMENT, and the Enrol & Collect page opens the panel for that bill
| from `?charge=`.
|
| "Awaiting its first payment" is ONE predicate, in ChargeQueryService: no
| allocation from a payment that is not reversed, not written off, and
| something outstanding. A bill whose only payment was reversed counts as
| awaiting again (lead decision, flagged for the owner in the pull request).
| The button reads that predicate from the table query, and the page re-checks
| it on the server, so a URL is never trusted.
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

it('hides Collect payment on a bill whose payment is live, even a partial one', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000');

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionHidden(($this->collectPaymentAction)($charge));
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
    // LEAD DECISION, flagged for the owner: a reversed payment is not a
    // payment for this purpose, so the bill is awaiting its first one again.
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000', reversed: true);

    Livewire::actingAs($this->collector)
        ->test(ListCharges::class)
        ->assertActionVisible(($this->collectPaymentAction)($charge));
});

it('still hides Collect payment when a live payment stands beside a reversed one', function () {
    $charge = Charge::factory()->create(['list_price' => '100.125']);
    ($this->payTowards)($charge, '40.000', reversed: true);
    ($this->payTowards)($charge, '25.000');

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
    'part paid with a live payment' => [function ($test) {
        $charge = Charge::factory()->create(['list_price' => '100.125']);
        ($test->payTowards)($charge, '40.000');

        return $charge;
    }],
    'written off' => [fn ($test) => Charge::factory()->writtenOff($test->collector)->create(['list_price' => '100.125'])],
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

it('refuses an actor without create_payment the same way, even for a bill that is awaiting its first payment', function () {
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
| The panel is opened for a bill awaiting its first payment, but the bill can
| change before the button is pressed, and the panel's bill id is a client-side
| property. finalize() therefore checks the same predicate again before writing.
| Its own payment is the one exception, so a double-click still replays.
*/

it('refuses the second of two tabs opened on the same link once the first has paid', function () {
    $charge = Charge::factory()->create(['list_price' => '100.000']);
    $open = fn () => Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm(['amount' => '40.000', 'tenders' => [['method' => 'cash', 'amount' => '40.000', 'external_reference' => null]]], 'collectForm');

    $first = $open();
    $second = $open();

    $first->call('finalize')->assertNotified(__('collect.collected_successfully'));
    $second->call('finalize')->assertNotified(__('collect.charge_not_collectable'));

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['40.000'])
        ->and(Payment::query()->count())->toBe(1);
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

it('refuses a panel whose bill id was rewritten to a bill that cannot take a first payment', function (Closure $makeTarget) {
    $opened = Charge::factory()->create(['list_price' => '100.000']);
    $target = $makeTarget($this);
    $before = PaymentAllocation::query()->where('charge_id', $target->getKey())->count();

    $page = Livewire::actingAs($this->collector)
        ->withQueryParams(['charge' => $opened->getKey()])
        ->test(EnrollAndCollect::class);
    collectUnpaidSentNotifications();

    $page->set('collectionChargeId', $target->getKey())
        ->fillForm(['amount' => '10.000', 'tenders' => [['method' => 'cash', 'amount' => '10.000', 'external_reference' => null]]], 'collectForm')
        ->call('finalize');

    expect(PaymentAllocation::query()->where('charge_id', $target->getKey())->count())->toBe($before)
        ->and(PaymentAllocation::query()->where('charge_id', $opened->getKey())->count())->toBe(0)
        ->and(collectUnpaidSentNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
})->with([
    'a bill with a live partial payment' => [function ($test): Charge {
        $charge = Charge::factory()->create(['list_price' => '100.000']);
        ($test->payTowards)($charge, '30.000');

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
