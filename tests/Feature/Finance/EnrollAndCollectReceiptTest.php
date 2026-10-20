<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The receipt after Collect payment (P4-T35)
|--------------------------------------------------------------------------
|
| Once finalize() has recorded this panel's payment, the panel shows what was
| recorded and a Print receipt link to the existing print page, instead of
| offering the collect form again. Printing is a click, never automatic.
|
| The actors are custom roles holding exactly the abilities under test, never
| super admin: that role holds every permission and would pass whichever
| ability the page checks. The pair that matters is the collector who may view
| payments and the collector who may not.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->actorHolding = function (string $name, array $permissions): User {
        $role = Role::findOrCreate($name, 'web');
        $this->system->syncRolePermissions($role, $permissions);
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $name);

        return $user->refresh();
    };

    $collecting = ['access_admin_panel', 'create_enrollment', 'create_payment'];
    $this->printer = ($this->actorHolding)('desk_that_prints', [...$collecting, 'view_payment']);
    $this->nonPrinter = ($this->actorHolding)('desk_that_cannot_view_payments', $collecting);

    /** Enrols a student on a 1,000.000 batch and records a split payment: 300.000 card, 700.000 cash. */
    $this->collectSplit = function (User $actor): Testable {
        $component = Livewire::actingAs($actor)
            ->test(EnrollAndCollect::class)
            ->fillForm([
                'student_id' => Student::factory()->create()->getKey(),
                'batch_id' => Batch::factory()->create(['price' => '1000.000'])->getKey(),
            ])
            ->call('confirm');

        return $component
            ->fillForm([
                'amount' => '1000.000',
                'tenders' => [
                    ['method' => TenderMethod::Card->value, 'amount' => '300.000', 'external_reference' => 'AUTH-300'],
                    ['method' => TenderMethod::Cash->value, 'amount' => '700.000', 'external_reference' => null],
                ],
            ], 'collectForm')
            ->call('finalize')
            ->assertHasNoFormErrors([], 'collectForm');
    };

    $this->recorded = fn (Testable $component): ?object => HTMLDocument::createFromString($component->html(), LIBXML_NOERROR)
        ->querySelector('[data-payment-recorded]');
    $this->printLink = fn (Testable $component): ?object => HTMLDocument::createFromString($component->html(), LIBXML_NOERROR)
        ->querySelector('a[data-print-receipt]');
});

it('shows the receipt reference, the amount and each tender once the payment is recorded', function () {
    $component = ($this->collectSplit)($this->printer);
    $payment = Payment::query()->sole();

    $section = ($this->recorded)($component);
    expect($section)->not->toBeNull();

    $text = preg_replace('/\s+/', ' ', $section->textContent);

    expect($payment->reference)->toStartWith('RCT-')
        ->and($text)->toContain('Payment recorded')
        ->toContain($payment->reference)
        ->toContain('1,000.000 LYD')
        ->toContain('Card')->toContain('300.000 LYD')->toContain('AUTH-300')
        ->toContain('Cash')->toContain('700.000 LYD');
});

it('points Print receipt at the payments print page and opens it in a new tab', function () {
    $component = ($this->collectSplit)($this->printer);
    $payment = Payment::query()->sole();

    $link = ($this->printLink)($component);

    expect($link)->not->toBeNull()
        ->and($link->getAttribute('href'))->toBe(route('finance.receipts.print', $payment))
        ->and($link->getAttribute('target'))->toBe('_blank')
        ->and(preg_replace('/\s+/', ' ', trim($link->textContent)))->toBe('Print receipt');
});

it('never prints by itself', function () {
    $component = ($this->collectSplit)($this->printer);

    $component->assertNoRedirect();

    expect($component->html())->not->toContain('window.print');
});

it('hides Print receipt from a collector who may not view payments, while still showing what was recorded', function () {
    $component = ($this->collectSplit)($this->nonPrinter);

    expect(($this->recorded)($component))->not->toBeNull()
        ->and(($this->printLink)($component))->toBeNull()
        ->and($component->html())->not->toContain('/print');
});

it('hides Print receipt when the payment has no receipt snapshot', function () {
    $component = ($this->collectSplit)($this->printer);
    expect(($this->printLink)($component))->not->toBeNull();

    PaymentReceiptSnapshot::query()->delete();
    $component->call('$refresh');

    expect(($this->recorded)($component))->not->toBeNull()
        ->and(($this->printLink)($component))->toBeNull();
});

it('offers the collect form before the payment and not again after it', function () {
    $component = Livewire::actingAs($this->printer)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => Student::factory()->create()->getKey(),
            'batch_id' => Batch::factory()->create(['price' => '1000.000'])->getKey(),
        ])
        ->call('confirm');

    expect($component->html())->toContain('Amount to collect')->toContain('Collect payment')
        ->and(($this->recorded)($component))->toBeNull()
        ->and(($this->printLink)($component))->toBeNull();

    $component
        ->fillForm([
            'amount' => '1000.000',
            'tenders' => [['method' => TenderMethod::Cash->value, 'amount' => '1000.000', 'external_reference' => null]],
        ], 'collectForm')
        ->call('finalize');

    expect($component->html())->not->toContain('Amount to collect')->not->toContain('Collect payment')
        ->and(($this->recorded)($component))->not->toBeNull();
});

it('shows no receipt when the payment was refused', function () {
    $component = Livewire::actingAs($this->printer)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => Student::factory()->create()->getKey(),
            'batch_id' => Batch::factory()->create(['price' => '1000.000'])->getKey(),
        ])
        ->call('confirm')
        ->fillForm([
            'amount' => '1000.001',
            'tenders' => [['method' => TenderMethod::Cash->value, 'amount' => '1000.001', 'external_reference' => null]],
        ], 'collectForm')
        ->call('finalize');

    expect(Payment::query()->count())->toBe(0)
        ->and(($this->recorded)($component))->toBeNull()
        ->and($component->html())->toContain('Amount to collect');
});

it('clears the page when the operator finishes the enrolment', function () {
    $component = ($this->collectSplit)($this->printer);
    expect(($this->recorded)($component))->not->toBeNull();

    $component->call('finishCollection')
        ->assertSet('collectionChargeId', null)
        ->assertSet('collectionIdempotencyKey', null)
        ->assertSet('flowStep', 1);

    expect(($this->recorded)($component))->toBeNull()
        ->and(($this->printLink)($component))->toBeNull()
        ->and($component->html())->not->toContain('Payment recorded');
});
