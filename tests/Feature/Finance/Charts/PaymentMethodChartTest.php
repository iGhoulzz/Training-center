<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Widgets\PaymentMethodChart;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $role = Role::findOrCreate('chart_viewer', 'web');
    $system = app(SystemRoleWriter::class);
    $system->syncRolePermissions($role, ['view_financial_report']);
    $this->viewer = User::factory()->create(['is_active' => true]);
    $system->assignRoles($this->viewer, 'chart_viewer');
    $this->actingAs($this->viewer->refresh());
    $this->reportOptions = ['from' => '2026-10-01', 'to' => '2026-10-31'];
    $this->chartData = fn ($widget): array => (new ReflectionMethod($widget, 'getData'))->invoke($widget);
});

it('plots the exact PaymentMethod report rows with hand-computed signed decimal values', function () {
    $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
    PaymentTender::factory()->for($payment)->create(['amount' => '75.125']);
    PaymentTender::factory()->card()->for($payment)->create(['amount' => '25.875']);
    PaymentTender::factory()->bankTransfer()->for($payment)->create(['amount' => '5.001']);
    PaymentTender::factory()->for($payment)->create(['method' => TenderMethod::Other, 'amount' => '9.999']);
    $reversed = Payment::factory()->reversed()->create(['received_at' => '2026-10-06 10:00:00']);
    PaymentTender::factory()->for($reversed)->create(['amount' => '900.000']);
    $outside = Payment::factory()->create(['received_at' => '2026-09-06 10:00:00']);
    PaymentTender::factory()->for($outside)->create(['amount' => '800.000']);

    $dataset = app(ReportDataBuilder::class)->build(ReportKind::PaymentMethod, $this->reportOptions, $this->viewer);
    $widget = Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->instance();
    $data = ($this->chartData)($widget);

    expect($data['labels'] ?? null)->toBe(['Bank transfer', 'Card', 'Cash', 'Other'])
        ->and($data['datasets'][0]['data'] ?? null)->toBe(['5.001', '25.875', '75.125', '9.999'])
        ->and($data['datasets'][0]['data'])->toBe(array_column(array_column($dataset->rows, 'cells'), 'total'));
});

it('preserves the report meaning when the period has no data', function () {
    $widget = Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->instance();
    $dataset = app(ReportDataBuilder::class)->build(ReportKind::PaymentMethod, $this->reportOptions, $this->viewer);
    expect($dataset->rows)->toBe([])
        ->and(($this->chartData)($widget))->toBe([])
        ->and($widget->isEmpty())->toBeTrue();
});

it('passes only applied filters to its mounted header chart', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $page = Livewire::test(PaymentMethodReportPage::class)->assertSeeLivewire(PaymentMethodChart::class);
    expect($page->instance()->getWidgetData()['reportFilters'] ?? null)->toBe($this->reportOptions);

    $edited = ['from' => '2026-09-01', 'to' => '2026-09-30'];
    $page->set('filters', $edited);
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($this->reportOptions);
    $page->call('applyFilters')->assertHasNoFormErrors();
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($edited);
});

it('updates the existing child chart only when the edited period is applied', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    foreach (['2026-10' => '100.125', '2026-09' => '75.001'] as $month => $amount) {
        $payment = Payment::factory()->create(['received_at' => $month.'-05 10:00:00']);
        PaymentTender::factory()->for($payment)->create(['amount' => $amount]);
    }
    $page = Livewire::test(PaymentMethodReportPage::class);
    preg_match_all('/wire:snapshot="([^"]*)"/', $page->html(), $matches);
    $snapshots = array_map(fn (string $encoded): array => json_decode(html_entity_decode($encoded, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    $parent = collect($snapshots)->first(fn (array $snapshot): bool => isset($snapshot['data']['appliedFilters']));
    $child = collect($snapshots)->first(fn (array $snapshot): bool => isset($snapshot['data']['reportFilters']));

    expect($child)->not->toBeNull()
        ->and($child['data']['reportFilters'][0])->toBe($this->reportOptions);

    $edited = ['from' => '2026-09-01', 'to' => '2026-09-30'];
    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [
            ['snapshot' => json_encode($parent), 'updates' => ['filters' => $edited], 'calls' => []],
            ['snapshot' => json_encode($child), 'updates' => [], 'calls' => [['method' => '$commit', 'params' => []]]],
        ],
    ], ['X-Livewire' => 'true'])->assertSuccessful();

    $parent = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    $child = json_decode($response->json('components.1.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($child['data']['reportFilters'][0])->toBe($this->reportOptions)
        ->and($response->json('components.1.effects.dispatches') ?? [])->toBe([]);

    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [
            ['snapshot' => json_encode($parent), 'updates' => [], 'calls' => [['method' => 'applyFilters', 'params' => []]]],
            ['snapshot' => json_encode($child), 'updates' => [], 'calls' => [['method' => '$commit', 'params' => []]]],
        ],
    ], ['X-Livewire' => 'true'])->assertSuccessful();

    $updatedChild = json_decode($response->json('components.1.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($updatedChild['memo']['id'])->toBe($child['memo']['id'])
        ->and($updatedChild['data']['reportFilters'][0])->toBe($edited)
        ->and($response->json('components.1.effects.dispatches.0.name'))->toBe('updateChartData')
        ->and($response->json('components.1.effects.dispatches.0.params.data.datasets.0.data'))->toBe(['75.001']);
});

it('refuses an independently mounted chart without report permission', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));
    Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an inactive report permission holder', function () {
    $this->viewer->forceFill(['is_active' => false])->saveQuietly();
    $this->actingAs($this->viewer->refresh());
    Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an unauthenticated chart request', function () {
    auth()->logout();
    Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});
