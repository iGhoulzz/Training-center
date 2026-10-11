<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Filament\Widgets\WageCostChart;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
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
    $this->reportOptions = ['from' => '2026-10', 'to' => '2026-10'];
    $this->chartData = fn ($widget): array => (new ReflectionMethod($widget, 'getData'))->invoke($widget);
});

it('plots the exact WageCost report rows with hand-computed signed decimal values', function () {
    $first = User::factory()->create(['name' => 'First instructor']);
    $second = User::factory()->create(['name' => 'Second instructor']);
    $line = PayrollLine::factory()->create(['user_id' => $first->getKey(), 'computed_amount' => '100.125']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '25.125']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '-5.001']);
    $line->forceFill(['finalized_at' => '2026-10-28 10:00:00', 'posting_period_start' => '2026-10-01'])->saveQuietly();
    PayrollLine::factory()->finalized()->create(['user_id' => $second->getKey(), 'posting_period_start' => '2026-10-01', 'computed_amount' => '99.001']);
    PayrollLine::factory()->create(['computed_amount' => '900.000']);
    PayrollLine::factory()->finalized()->create(['posting_period_start' => '2026-09-01', 'computed_amount' => '800.000']);

    $dataset = app(ReportDataBuilder::class)->build(ReportKind::WageCost, $this->reportOptions, $this->viewer);
    $widget = Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->instance();
    $data = ($this->chartData)($widget);

    expect($data['labels'] ?? null)->toBe(['First instructor', 'Second instructor'])
        ->and($data['datasets'][0]['data'] ?? null)->toBe(['120.249', '99.001'])
        ->and($data['datasets'][0]['data'])->toBe(array_column(array_column($dataset->rows, 'cells'), 'total'));
});

it('preserves the report meaning when the period has no data', function () {
    $widget = Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->instance();
    $dataset = app(ReportDataBuilder::class)->build(ReportKind::WageCost, $this->reportOptions, $this->viewer);
    expect($dataset->rows)->toBe([])
        ->and(($this->chartData)($widget))->toBe([])
        ->and($widget->isEmpty())->toBeTrue();
});

it('passes only applied filters to its mounted header chart', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $page = Livewire::test(WageCostReportPage::class)->assertSeeLivewire(WageCostChart::class);
    expect($page->instance()->getWidgetData()['reportFilters'] ?? null)->toBe($this->reportOptions);

    $edited = ['from' => '2026-09', 'to' => '2026-09'];
    $page->set('filters', $edited);
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($this->reportOptions);
    $page->call('applyFilters')->assertHasNoFormErrors();
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($edited);
});

it('updates the existing child chart only when the edited period is applied', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $person = User::factory()->create();
    foreach (['2026-10' => '100.125', '2026-09' => '75.001'] as $month => $amount) {
        PayrollLine::factory()->finalized()->create([
            'user_id' => $person->getKey(),
            'segment_start' => $month.'-01',
            'segment_end' => $month === '2026-10' ? '2026-10-31' : '2026-09-30',
            'posting_period_start' => $month.'-01',
            'computed_amount' => $amount,
        ]);
    }
    $page = Livewire::test(WageCostReportPage::class);
    preg_match_all('/wire:snapshot="([^"]*)"/', $page->html(), $matches);
    $snapshots = array_map(fn (string $encoded): array => json_decode(html_entity_decode($encoded, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    $parent = collect($snapshots)->first(fn (array $snapshot): bool => isset($snapshot['data']['appliedFilters']));
    $child = collect($snapshots)->first(fn (array $snapshot): bool => isset($snapshot['data']['reportFilters']));

    expect($child)->not->toBeNull()
        ->and($child['data']['reportFilters'][0])->toBe($this->reportOptions);

    $edited = ['from' => '2026-09', 'to' => '2026-09'];
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
    Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an inactive report permission holder', function () {
    $this->viewer->forceFill(['is_active' => false])->saveQuietly();
    $this->actingAs($this->viewer->refresh());
    Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an unauthenticated chart request', function () {
    auth()->logout();
    Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});
