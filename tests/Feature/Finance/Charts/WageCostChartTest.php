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
use Dom\HTMLDocument;
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
    $this->bars = function (string $html): array {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bars = [];
        foreach ($document->querySelectorAll('[data-report-bar]') as $item) {
            $rects = $item->querySelectorAll('rect');
            $bars[] = [
                'label' => trim($item->querySelector('span')->textContent),
                'value' => trim($item->querySelector('[data-bar-value]')->textContent),
                'width' => $rects[1]->getAttribute('width'),
                'tone' => $item->querySelector('svg')->getAttribute('data-bar-tone'),
                'set_apart' => str_contains($item->getAttribute('class'), 'border-t'),
            ];
        }

        return $bars;
    };
    $this->period = fn (string $html): ?string => HTMLDocument::createFromString($html, LIBXML_NOERROR)
        ->querySelector('[data-report-period]')?->textContent;
});

it('draws each staff member as a bar, largest first, with its value as text and the period it covers', function () {
    $small = User::factory()->create(['name' => 'Cara']);
    $medium = User::factory()->create(['name' => 'Amira']);
    $large = User::factory()->create(['name' => 'Bilal']);
    $line = PayrollLine::factory()->create(['user_id' => $large->getKey(), 'computed_amount' => '100.125']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '25.125']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '-5.001']);
    $line->forceFill(['finalized_at' => '2026-10-28 10:00:00', 'posting_period_start' => '2026-10-01'])->saveQuietly();
    PayrollLine::factory()->finalized()->create(['user_id' => $medium->getKey(), 'posting_period_start' => '2026-10-01', 'computed_amount' => '99.001']);
    PayrollLine::factory()->finalized()->create(['user_id' => $small->getKey(), 'posting_period_start' => '2026-10-01', 'computed_amount' => '10.000']);
    PayrollLine::factory()->create(['computed_amount' => '900.000']);
    PayrollLine::factory()->finalized()->create(['posting_period_start' => '2026-09-01', 'computed_amount' => '800.000']);

    $html = Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->html();

    // 120.249 is the full bar. 99.001 / 120.249 is 82.33% and 10.000 / 120.249 is 8.32%.
    expect(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and(($this->bars)($html))->toBe([
            ['label' => 'Bilal', 'value' => '120.249 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'Amira', 'value' => '99.001 LYD', 'width' => '82', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'Cara', 'value' => '10.000 LYD', 'width' => '8', 'tone' => 'primary', 'set_apart' => false],
        ]);
});

it('runs a monthly period from the first day of its first month to the last day of its last month', function (string $from, string $to, string $expected) {
    $html = Livewire::test(WageCostChart::class, ['reportFilters' => ['from' => $from, 'to' => $to], 'lazy' => false])->html();

    expect(($this->period)($html))->toBe($expected);
})->with([
    'one month' => ['2026-10', '2026-10', '1 Oct 2026 – 31 Oct 2026'],
    'several months' => ['2026-08', '2026-10', '1 Aug 2026 – 31 Oct 2026'],
    'a leap february' => ['2028-02', '2028-02', '1 Feb 2028 – 29 Feb 2028'],
    'across a year' => ['2026-11', '2027-02', '1 Nov 2026 – 28 Feb 2027'],
]);

it('draws the same rows as the table beneath it', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    PayrollLine::factory()->finalized()->create(['user_id' => User::factory()->create(['name' => 'Amira'])->getKey(), 'posting_period_start' => '2026-10-01', 'computed_amount' => '99.001']);
    PayrollLine::factory()->finalized()->create(['user_id' => User::factory()->create(['name' => 'Cara'])->getKey(), 'posting_period_start' => '2026-10-01', 'computed_amount' => '10.000']);

    $html = Livewire::test(WageCostReportPage::class)->html();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $tableCells = array_map(fn ($cell): string => trim($cell->textContent), iterator_to_array($document->querySelectorAll('tbody td')));

    expect(array_column(($this->bars)($html), 'label'))->toBe(['Amira', 'Cara'])
        ->and($tableCells)->toContain('Amira', 'Cara', '99.001', '10.000');
});

it('says so, still naming the period, when the period has no data', function () {
    $html = Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();

    expect(($this->bars)($html))->toBe([])
        ->and(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and($html)->toContain('No rows match these filters.');
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
    $person = User::factory()->create(['name' => 'Chart person']);
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
        ->and(array_column(($this->bars)($response->json('components.1.effects.html')), 'value'))->toContain('100.125 LYD');

    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [
            ['snapshot' => json_encode($parent), 'updates' => [], 'calls' => [['method' => 'applyFilters', 'params' => []]]],
            ['snapshot' => json_encode($child), 'updates' => [], 'calls' => [['method' => '$commit', 'params' => []]]],
        ],
    ], ['X-Livewire' => 'true'])->assertSuccessful();

    $updatedChild = json_decode($response->json('components.1.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($updatedChild['memo']['id'])->toBe($child['memo']['id'])
        ->and($updatedChild['data']['reportFilters'][0])->toBe($edited)
        ->and(($this->bars)($response->json('components.1.effects.html')))->toBe([
            ['label' => 'Chart person', 'value' => '75.001 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
        ])
        ->and(($this->period)($response->json('components.1.effects.html')))->toBe('1 Sep 2026 – 30 Sep 2026');
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

it('keeps the minus on a negative wage total, as the table under the chart does', function () {
    // Codex review of #124: an unsigned chart drew -25.001 as 25.001.
    $staff = User::factory()->create(['name' => 'Dana']);
    $line = PayrollLine::factory()->create(['user_id' => $staff->getKey(), 'computed_amount' => '100.000']);
    PayrollLineAdjustment::factory()->create(['payroll_line_id' => $line->getKey(), 'amount' => '-125.001']);
    $line->forceFill(['finalized_at' => '2026-10-28 10:00:00', 'posting_period_start' => '2026-10-01'])->saveQuietly();

    $html = Livewire::test(WageCostChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();
    // The table renders the ReportDataBuilder dataset, the same rows the chart draws.
    $cells = array_column(app(ReportDataBuilder::class)->build(ReportKind::WageCost, $this->reportOptions, $this->viewer)->rows, 'cells');

    expect(($this->bars)($html))->toBe([
        ['label' => 'Dana', 'value' => '−25.001 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
    ])->and(array_column($cells, 'total'))->toBe(['-25.001']);
});
