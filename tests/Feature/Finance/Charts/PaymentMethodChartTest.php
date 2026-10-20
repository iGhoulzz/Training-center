<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Widgets\PaymentMethodChart;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
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
    $this->reportOptions = ['from' => '2026-10-01', 'to' => '2026-10-31'];
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

it('draws each payment method as a bar, largest first, with its value as text and the period it covers', function () {
    $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
    PaymentTender::factory()->for($payment)->create(['amount' => '75.125']);
    PaymentTender::factory()->card()->for($payment)->create(['amount' => '25.875']);
    PaymentTender::factory()->bankTransfer()->for($payment)->create(['amount' => '5.001']);
    PaymentTender::factory()->for($payment)->create(['method' => TenderMethod::Other, 'amount' => '9.999']);
    $reversed = Payment::factory()->reversed()->create(['received_at' => '2026-10-06 10:00:00']);
    PaymentTender::factory()->for($reversed)->create(['amount' => '900.000']);
    $outside = Payment::factory()->create(['received_at' => '2026-09-06 10:00:00']);
    PaymentTender::factory()->for($outside)->create(['amount' => '800.000']);

    $html = Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->html();

    // 75.125 is the full bar. 25.875 / 75.125 is 34.44%, 9.999 / 75.125 is 13.31%, 5.001 / 75.125 is 6.66%.
    expect(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and(($this->bars)($html))->toBe([
            ['label' => 'Cash', 'value' => '75.125 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'Card', 'value' => '25.875 LYD', 'width' => '34', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'Other', 'value' => '9.999 LYD', 'width' => '13', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'Bank transfer', 'value' => '5.001 LYD', 'width' => '7', 'tone' => 'primary', 'set_apart' => false],
        ]);
});

it('draws the same rows as the table beneath it', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
    PaymentTender::factory()->for($payment)->create(['amount' => '75.125']);
    PaymentTender::factory()->card()->for($payment)->create(['amount' => '25.875']);

    $html = Livewire::test(PaymentMethodReportPage::class)->html();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $tableCells = array_map(fn ($cell): string => trim($cell->textContent), iterator_to_array($document->querySelectorAll('tbody td')));

    expect(array_column(($this->bars)($html), 'label'))->toBe(['Cash', 'Card'])
        ->and($tableCells)->toContain('Cash', 'Card', '75.125', '25.875');
});

it('says so, still naming the period, when the period has no data', function () {
    $html = Livewire::test(PaymentMethodChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();

    expect(($this->bars)($html))->toBe([])
        ->and(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and($html)->toContain('No rows match these filters.');
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
            ['label' => 'Cash', 'value' => '75.001 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
        ])
        ->and(($this->period)($response->json('components.1.effects.html')))->toBe('1 Sep 2026 – 30 Sep 2026');
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
