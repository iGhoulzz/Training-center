<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\ChargeNotAwaitingFirstPaymentException;
use App\Domain\Finance\Exceptions\IdempotencyConflictException;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| "First payment only" is a rule of RecordPaymentAction (P4-T32 fix round)
|--------------------------------------------------------------------------
|
| The collection panel checks that a bill still awaits its first payment, but
| that check runs before RecordPaymentAction takes the charge lock, so two panel
| sessions with different idempotency keys could both pass it and both be
| accepted while their sum stayed under the outstanding balance: a second
| installment through the first-payment screen (design section 12).
|
| The panel therefore sets RecordPaymentData::$firstPaymentOnly, and the Action
| refuses inside its transaction, under the charge row lock, once that bill has
| a live payment. This file proves the rule at the Action and through the page;
| CollectFirstPaymentConcurrencyTest proves it against two real connections.
|
| EVERY ACTOR HOLDS EXACTLY create_payment, NEVER A ROLE.
*/
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title and status.
 *
 * @return list<array{title: string, status: string}>
 */
function firstPaymentOnlyNotifications(): array
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
        $actor->givePermissionTo($permissions);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    $this->till = ($this->actor)(['create_payment']);

    // Everything the page needs to open and submit: reach the admin panel, see the bill, enrol, and take money.
    $this->deskUser = ($this->actor)(['access_admin_panel', 'view_any_charge', 'view_charge', 'create_enrollment', 'create_payment']);

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

    $this->collect = fn (Charge $charge, string $amount, ?string $key = null, bool $firstPaymentOnly = true, ?User $by = null): Payment => app(RecordPaymentAction::class)->execute(
        $by ?? $this->till,
        new RecordPaymentData(
            chargeId: (int) $charge->getKey(),
            allocation: $amount,
            tenders: [new TenderData(TenderMethod::Cash, $amount)],
            idempotencyKey: $key ?? Str::uuid()->toString(),
            firstPaymentOnly: $firstPaymentOnly,
        ),
    );
});

/*
|--------------------------------------------------------------------------
| The Action
|--------------------------------------------------------------------------
*/

it('refuses a first-payment-only collection on a bill that already has a live partial payment, writing nothing', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');

    $paymentsBefore = Payment::query()->count();
    $tendersBefore = PaymentTender::query()->count();
    $activityBefore = Activity::query()->count();

    $thrown = null;

    try {
        ($this->collect)($charge, '300.000');
    } catch (ChargeNotAwaitingFirstPaymentException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeNotAwaitingFirstPaymentException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey())
        ->and($thrown->getMessage())->toBe(__('collect.charge_not_collectable'))
        ->and(Payment::query()->count())->toBe($paymentsBefore)
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['400.000'])
        ->and(PaymentTender::query()->count())->toBe($tendersBefore)
        ->and(Activity::query()->count())->toBe($activityBefore);
});

it('still records the second payment on the same bill when first-payment-only is off', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');

    ($this->collect)($charge, '300.000', firstPaymentOnly: false);

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())
        ->toBe(['400.000', '300.000']);
});

it('defaults to off, so a caller that never heard of the flag is unchanged', function () {
    $data = new RecordPaymentData(
        chargeId: 1,
        allocation: '10.000',
        tenders: [new TenderData(TenderMethod::Cash, '10.000')],
        idempotencyKey: 'SENTINEL-KEY',
    );

    expect($data->firstPaymentOnly)->toBeFalse();
});

it('returns the original payment when the same key is replayed with first-payment-only on', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = (string) Str::uuid();

    $first = ($this->collect)($charge, '300.000', $key);
    $replay = ($this->collect)($charge, '300.000', $key);

    expect($replay->getKey())->toBe($first->getKey())
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->sole()->amount)->toBe('300.000');
});

it('refuses a different amount under the same key as a conflict, not as a missing first payment', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = (string) Str::uuid();

    ($this->collect)($charge, '300.000', $key);

    expect(fn () => ($this->collect)($charge, '200.000', $key))
        ->toThrow(IdempotencyConflictException::class);
});

it('decides exactly as ChargeQueryService::awaitingFirstPaymentSql does, for every state of a bill', function (Closure $makeCharge, bool $awaiting) {
    $charge = $makeCharge($this);

    // The predicate that offers the button, asked of the database.
    $offered = Charge::query()
        ->whereKey($charge->getKey())
        ->whereRaw(app(ChargeQueryService::class)->awaitingFirstPaymentSql())
        ->exists();

    $outcome = null;

    try {
        ($this->collect)($charge, '10.000');
        $outcome = 'recorded';
    } catch (ChargeNotAwaitingFirstPaymentException) {
        $outcome = 'refused';
    }

    expect($offered)->toBe($awaiting)
        ->and($outcome)->toBe($awaiting ? 'recorded' : 'refused');
})->with([
    'an unpaid bill' => [fn ($test): Charge => Charge::factory()->create(['amount' => '100.000']), true],
    'a bill whose only payment was reversed' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '40.000', reversed: true);

        return $charge;
    }, true],
    'a bill with a live partial payment' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '40.000');

        return $charge;
    }, false],
    'a bill with a live payment beside a reversed one' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '40.000', reversed: true);
        ($test->payTowards)($charge, '25.000');

        return $charge;
    }, false],
    'a bill paid in full' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '100.000');

        return $charge;
    }, false],
    'a written-off bill' => [fn ($test): Charge => Charge::factory()->writtenOff($test->till)->create(['amount' => '100.000']), false],
    'a bill with nothing outstanding and no payment' => [fn ($test): Charge => Charge::factory()->create(['list_price' => '0.000']), false],
]);

it('answers an actor without create_payment with an authorization failure before it says anything about the bill', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');

    $without = ($this->actor)(['view_payment']);

    expect(fn () => ($this->collect)($charge, '300.000', by: $without))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| The page: the pre-check passes, then another session pays first
|--------------------------------------------------------------------------
*/

it('refuses with the notice when another session pays between the page check and the Action, recording nothing more', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    $page = Livewire::actingAs($this->deskUser)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'amount' => '300.000',
            'tenders' => [['method' => 'cash', 'amount' => '300.000', 'external_reference' => null]],
        ], 'collectForm');
    firstPaymentOnlyNotifications();

    /*
     * THE INTERLEAVING, MADE DETERMINISTIC. finalize() asks the page's own
     * pre-check, then resolves the Action to run it. The resolving hook fires in
     * between, so a competing session's payment (a different key) commits AFTER
     * the pre-check has said yes and BEFORE the Action takes its lock.
     */
    $competed = false;
    app()->resolving(RecordPaymentAction::class, function () use (&$competed, $charge): void {
        if ($competed) {
            return;
        }

        $competed = true;
        ($this->collect)($charge, '400.000', firstPaymentOnly: false);
    });

    $page->call('finalize');

    expect($competed)->toBeTrue('The competing payment was never injected, so this test proved nothing.')
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['400.000'])
        ->and(Payment::query()->count())->toBe(1)
        ->and(firstPaymentOnlyNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
});

it('records the first payment from the page as before, with no refusal', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    Livewire::actingAs($this->deskUser)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'amount' => '300.000',
            'tenders' => [['method' => 'cash', 'amount' => '300.000', 'external_reference' => null]],
        ], 'collectForm')
        ->call('finalize')
        ->assertNotified(__('collect.collected_successfully'));

    expect(PaymentAllocation::query()->sole()->amount)->toBe('300.000');
});
