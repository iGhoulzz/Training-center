<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\ChargeNotCollectableException;
use App\Domain\Finance\Exceptions\IdempotencyConflictException;
use App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException;
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
| "Collectable only" is a rule of RecordPaymentAction (P4-T32 fix round, P4-T36)
|--------------------------------------------------------------------------
|
| The collection panel checks that a bill is still collectable (not written off,
| something outstanding; a payment already on it does NOT block, P4-T36), but
| that check runs before RecordPaymentAction takes the charge lock, so a till
| could write the bill off or settle it between the check and the write.
|
| The panel therefore sets RecordPaymentData::$collectableOnly, and the Action
| refuses inside its transaction, under the charge row lock, a bill that is no
| longer collectable. This file proves the rule at the Action and through the
| page; CollectFirstPaymentConcurrencyTest proves it against two real connections.
| The file keeps its P4-T32 name; it is where the parity dataset lives.
|
| EVERY ACTOR HOLDS EXACTLY create_payment, NEVER A ROLE.
*/
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title and status.
 *
 * @return list<array{title: string, status: string}>
 */
function collectNotifications(): array
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

    $this->collect = fn (Charge $charge, string $amount, ?string $key = null, bool $collectableOnly = true, ?User $by = null): Payment => app(RecordPaymentAction::class)->execute(
        $by ?? $this->till,
        new RecordPaymentData(
            chargeId: (int) $charge->getKey(),
            allocation: $amount,
            tenders: [new TenderData(TenderMethod::Cash, $amount)],
            idempotencyKey: $key ?? Str::uuid()->toString(),
            collectableOnly: $collectableOnly,
        ),
    );
});

/*
|--------------------------------------------------------------------------
| The Action
|--------------------------------------------------------------------------
*/

it('refuses a collectable-only collection on a written-off bill with a live partial payment, writing nothing', function () {
    $charge = Charge::factory()->writtenOff($this->till)->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');

    $paymentsBefore = Payment::query()->count();
    $tendersBefore = PaymentTender::query()->count();
    $activityBefore = Activity::query()->count();

    $thrown = null;

    try {
        ($this->collect)($charge, '300.000');
    } catch (ChargeNotCollectableException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeNotCollectableException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey())
        ->and($thrown->getMessage())->toBe(__('collect.charge_not_collectable'))
        ->and(Payment::query()->count())->toBe($paymentsBefore)
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['400.000'])
        ->and(PaymentTender::query()->count())->toBe($tendersBefore)
        ->and(Activity::query()->count())->toBe($activityBefore);
});

it('records a later installment on a bill that already has a live partial payment, writing one more of everything', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');
    $tendersBefore = PaymentTender::query()->count();

    $payment = ($this->collect)($charge, '300.000');

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())
        ->toBe(['400.000', '300.000'])
        ->and(Payment::query()->count())->toBe(2)
        ->and(PaymentTender::query()->count())->toBe($tendersBefore + 1)
        ->and($payment->receiptSnapshot->remaining_balance)->toBe('300.000');
});

it('refuses an installment above what the bill still owes with the invariant exception, writing nothing', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');
    $paymentsBefore = Payment::query()->count();

    $thrown = null;

    try {
        ($this->collect)($charge, '600.001');
    } catch (PaymentExceedsOutstandingException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(PaymentExceedsOutstandingException::class)
        ->and($thrown->attemptedAmount)->toBe('600.001')
        ->and($thrown->outstandingAmount)->toBe('600.000')
        ->and(Payment::query()->count())->toBe($paymentsBefore)
        ->and(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['400.000']);
});

it('still records the second payment on the same bill when collectable-only is off', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    ($this->payTowards)($charge, '400.000');

    ($this->collect)($charge, '300.000', collectableOnly: false);

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

    expect($data->collectableOnly)->toBeFalse();
});

it('returns the original payment when the same key is replayed with collectable-only on', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = (string) Str::uuid();

    $first = ($this->collect)($charge, '300.000', $key);
    $replay = ($this->collect)($charge, '300.000', $key);

    expect($replay->getKey())->toBe($first->getKey())
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->sole()->amount)->toBe('300.000');
});

it('refuses a different amount under the same key as a conflict, not as a bill that cannot be collected', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $key = (string) Str::uuid();

    ($this->collect)($charge, '300.000', $key);

    expect(fn () => ($this->collect)($charge, '200.000', $key))
        ->toThrow(IdempotencyConflictException::class);
});

it('decides exactly as ChargeQueryService::collectableSql does, for every state of a bill', function (Closure $makeCharge, bool $collectable) {
    $charge = $makeCharge($this);

    // The predicate that offers the button, asked of the database.
    $offered = Charge::query()
        ->whereKey($charge->getKey())
        ->whereRaw(app(ChargeQueryService::class)->collectableSql())
        ->exists();

    $outcome = null;

    try {
        ($this->collect)($charge, '10.000');
        $outcome = 'recorded';
    } catch (ChargeNotCollectableException) {
        $outcome = 'refused';
    }

    expect($offered)->toBe($collectable)
        ->and($outcome)->toBe($collectable ? 'recorded' : 'refused');
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
    }, true],
    'a bill with a live payment beside a reversed one' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '40.000', reversed: true);
        ($test->payTowards)($charge, '25.000');

        return $charge;
    }, true],
    'a bill with 10.000 left, the amount the dataset collects' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '90.000');

        return $charge;
    }, true],
    'a bill paid in full' => [function ($test): Charge {
        $charge = Charge::factory()->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '100.000');

        return $charge;
    }, false],
    'a written-off bill' => [fn ($test): Charge => Charge::factory()->writtenOff($test->till)->create(['amount' => '100.000']), false],
    'a written-off bill that also has a live partial payment' => [function ($test): Charge {
        $charge = Charge::factory()->writtenOff($test->till)->create(['amount' => '100.000']);
        ($test->payTowards)($charge, '40.000');

        return $charge;
    }, false],
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

/**
 * Open the panel on a 1000.000 bill and submit 300.000, with a competing session's payment
 * committing AFTER the page's pre-check has said yes and BEFORE the Action takes its lock.
 *
 * finalize() asks the page's own pre-check, then resolves the Action to run it, so the
 * resolving hook fires in between: the interleaving, made deterministic.
 *
 * @param  callable(Charge): void  $compete
 */
function finalizeWhileCompeting(object $test, Charge $charge, callable $compete): void
{
    $page = Livewire::actingAs($test->deskUser)
        ->withQueryParams(['charge' => $charge->getKey()])
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'amount' => '300.000',
            'tenders' => [['method' => 'cash', 'amount' => '300.000', 'external_reference' => null]],
        ], 'collectForm');
    collectNotifications();

    $competed = false;
    app()->resolving(RecordPaymentAction::class, function () use (&$competed, $charge, $compete): void {
        if ($competed) {
            return;
        }

        $competed = true;
        $compete($charge);
    });

    $page->call('finalize');

    expect($competed)->toBeTrue('The competing change was never injected, so this test proved nothing.');
}

it('refuses with the notice when another session settles the bill between the page check and the Action, recording nothing more', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    finalizeWhileCompeting($this, $charge, fn (Charge $charge) => ($this->collect)($charge, '1000.000', collectableOnly: false));

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['1000.000'])
        ->and(Payment::query()->count())->toBe(1)
        ->and(collectNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
});

it('refuses with the notice when another session writes the bill off between the page check and the Action', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    finalizeWhileCompeting($this, $charge, fn (Charge $charge) => $charge->forceFill([
        'written_off_at' => now(), 'written_off_by' => $this->till->getKey(), 'written_off_reason' => 'SENTINEL-WRITE-OFF',
    ])->save());

    expect(Payment::query()->count())->toBe(0)
        ->and(collectNotifications())->toBe([
            ['title' => __('collect.charge_not_collectable'), 'status' => 'danger'],
        ]);
});

it('records the installment when another session paid part of the bill meanwhile and the sum still fits', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    finalizeWhileCompeting($this, $charge, fn (Charge $charge) => ($this->collect)($charge, '400.000', collectableOnly: false));

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->orderBy('id')->pluck('amount')->all())->toBe(['400.000', '300.000'])
        ->and(collectNotifications())->toBe([
            ['title' => __('collect.collected_successfully'), 'status' => 'success'],
        ]);
});

it('refuses with both figures when another session paid part of the bill meanwhile and the sum no longer fits', function () {
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    finalizeWhileCompeting($this, $charge, fn (Charge $charge) => ($this->collect)($charge, '800.000', collectableOnly: false));

    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->values()->all();

    expect(PaymentAllocation::query()->where('charge_id', $charge->getKey())->pluck('amount')->all())->toBe(['800.000'])
        ->and(Payment::query()->count())->toBe(1)
        ->and($sent)->toHaveCount(1)
        ->and((string) $sent[0]->getTitle())->toBe(__('payments.exceeds_outstanding'))
        ->and((string) $sent[0]->getBody())->toBe('Attempted 300.000 LYD; outstanding 200.000 LYD.')
        ->and($sent[0]->getStatus())->toBe('danger');
});

it('records a payment from the page as before, with no refusal', function () {
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
