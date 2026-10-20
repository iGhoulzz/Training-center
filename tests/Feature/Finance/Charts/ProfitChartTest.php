<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Widgets\ProfitChart;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PayrollLine;
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

/** Seeds October 2026: revenue, finalized wages and expenses, plus noise that is reversed or outside the month. */
function seedProfitOctober(string $revenue, string $wages, string $expenses): void
{
    if ($revenue !== '0.000') {
        $enrollment = Enrollment::factory()->create();
        $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey()]);
        $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
        PaymentAllocation::factory()->create(['charge_id' => $charge->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $revenue]);
    }
    if ($wages !== '0.000') {
        PayrollLine::factory()->finalized()->create(['posting_period_start' => '2026-10-01', 'computed_amount' => $wages]);
    }
    if ($expenses !== '0.000') {
        Expense::factory()->create(['paid_on' => '2026-10-05', 'amount' => $expenses]);
    }
    Expense::factory()->reversed()->create(['paid_on' => '2026-10-06', 'amount' => '999.000']);
    Expense::factory()->create(['paid_on' => '2026-09-06', 'amount' => '888.000']);
}

it('draws a loss as revenue, wage cost, expenses and a red result set apart, with hand-computed signed values', function () {
    seedProfitOctober('100.125', '150.250', '25.001');

    $html = Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->html();

    // 150.250 is the largest absolute value: 100.125 is 66.64%, 25.001 is 16.64%, and the result, -75.126, is 50.00%.
    expect(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and(($this->bars)($html))->toBe([
            ['label' => 'Collected revenue', 'value' => '+100.125 LYD', 'width' => '67', 'tone' => 'success', 'set_apart' => false],
            ['label' => 'Wage cost', 'value' => '−150.250 LYD', 'width' => '100', 'tone' => 'danger', 'set_apart' => false],
            ['label' => 'Expenses', 'value' => '−25.001 LYD', 'width' => '17', 'tone' => 'danger', 'set_apart' => false],
            ['label' => 'Loss', 'value' => '−75.126 LYD', 'width' => '50', 'tone' => 'danger', 'set_apart' => true],
        ]);
});

it('draws a profit with a green result set apart', function () {
    seedProfitOctober('500.000', '100.000', '50.000');

    $html = Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();

    expect(($this->bars)($html))->toBe([
        ['label' => 'Collected revenue', 'value' => '+500.000 LYD', 'width' => '100', 'tone' => 'success', 'set_apart' => false],
        ['label' => 'Wage cost', 'value' => '−100.000 LYD', 'width' => '20', 'tone' => 'danger', 'set_apart' => false],
        ['label' => 'Expenses', 'value' => '−50.000 LYD', 'width' => '10', 'tone' => 'danger', 'set_apart' => false],
        ['label' => 'Profit', 'value' => '+350.000 LYD', 'width' => '70', 'tone' => 'success', 'set_apart' => true],
    ]);
});

it('keeps all four bars, empty and unsigned, when the period has no data', function () {
    $html = Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();

    expect(($this->bars)($html))->toBe([
        ['label' => 'Collected revenue', 'value' => '0.000 LYD', 'width' => '0', 'tone' => 'success', 'set_apart' => false],
        ['label' => 'Wage cost', 'value' => '0.000 LYD', 'width' => '0', 'tone' => 'danger', 'set_apart' => false],
        ['label' => 'Expenses', 'value' => '0.000 LYD', 'width' => '0', 'tone' => 'danger', 'set_apart' => false],
        ['label' => 'Profit', 'value' => '0.000 LYD', 'width' => '0', 'tone' => 'success', 'set_apart' => true],
    ])->and(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026');
});

it('names a monthly period from the first day of its first month to the last day of its last month', function () {
    $html = Livewire::test(ProfitChart::class, ['reportFilters' => ['from' => '2026-08', 'to' => '2026-10'], 'lazy' => false])->html();

    expect(($this->period)($html))->toBe('1 Aug 2026 – 31 Oct 2026');
});

it('draws the same totals as the table beneath it', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    seedProfitOctober('500.000', '100.000', '50.000');

    $html = Livewire::test(ProfitReportPage::class)->html();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $tableCells = array_map(fn ($cell): string => trim($cell->textContent), iterator_to_array($document->querySelectorAll('tbody td')));

    expect($tableCells)->toBe(['500.000', '100.000', '50.000', '350.000'])
        ->and(array_column(($this->bars)($html), 'value'))->toBe(['+500.000 LYD', '−100.000 LYD', '−50.000 LYD', '+350.000 LYD']);
});

it('passes only applied filters to its mounted header chart', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $page = Livewire::test(ProfitReportPage::class)->assertSeeLivewire(ProfitChart::class);
    expect($page->instance()->getWidgetData()['reportFilters'] ?? null)->toBe($this->reportOptions);

    $edited = ['from' => '2026-09', 'to' => '2026-09'];
    $page->set('filters', $edited);
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($this->reportOptions);
    $page->call('applyFilters')->assertHasNoFormErrors();
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($edited);
});

it('updates the existing child chart only when the edited period is applied', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    Expense::factory()->create(['paid_on' => '2026-10-05', 'amount' => '100.125']);
    Expense::factory()->create(['paid_on' => '2026-09-05', 'amount' => '75.001']);
    $page = Livewire::test(ProfitReportPage::class);
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
        ->and(array_column(($this->bars)($response->json('components.1.effects.html')), 'value'))->toContain('−100.125 LYD');

    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [
            ['snapshot' => json_encode($parent), 'updates' => [], 'calls' => [['method' => 'applyFilters', 'params' => []]]],
            ['snapshot' => json_encode($child), 'updates' => [], 'calls' => [['method' => '$commit', 'params' => []]]],
        ],
    ], ['X-Livewire' => 'true'])->assertSuccessful();

    $updatedChild = json_decode($response->json('components.1.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($updatedChild['memo']['id'])->toBe($child['memo']['id'])
        ->and($updatedChild['data']['reportFilters'][0])->toBe($edited)
        ->and(array_column(($this->bars)($response->json('components.1.effects.html')), 'value'))->toBe(['0.000 LYD', '0.000 LYD', '−75.001 LYD', '−75.001 LYD'])
        ->and(($this->period)($response->json('components.1.effects.html')))->toBe('1 Sep 2026 – 30 Sep 2026');
});

it('refuses an independently mounted chart without report permission', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));
    Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an inactive report permission holder', function () {
    $this->viewer->forceFill(['is_active' => false])->saveQuietly();
    $this->actingAs($this->viewer->refresh());
    Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an unauthenticated chart request', function () {
    auth()->logout();
    Livewire::test(ProfitChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});
