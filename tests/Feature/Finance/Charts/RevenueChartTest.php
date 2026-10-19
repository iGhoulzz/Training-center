<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Widgets\ReportBarsWidget;
use App\Domain\Finance\Filament\Widgets\RevenueChart;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Reactive;
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

it('draws each batch as a bar, largest first, with its value as text and the period it covers', function () {
    $course = Course::factory()->create(['code' => 'ENG-101']);
    $small = Batch::factory()->for($course)->create(['code' => 'ENG-A']);
    $medium = Batch::factory()->for($course)->create(['code' => 'ENG-B']);
    $large = Batch::factory()->for($course)->create(['code' => 'ENG-C']);
    foreach ([[$small, '40.000', false, '2026-10-05'], [$medium, '99.001', false, '2026-10-07'], [$large, '100.125', false, '2026-10-05'], [$large, '25.125', false, '2026-10-06'], [$large, '900.000', true, '2026-10-08'], [$large, '800.000', false, '2026-09-08']] as [$batch, $amount, $reversed, $date]) {
        $enrollment = Enrollment::factory()->for($batch)->create();
        $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey()]);
        $payment = ($reversed ? Payment::factory()->reversed() : Payment::factory())
            ->create(['received_at' => $date.' 10:00:00']);
        PaymentAllocation::factory()->create(['charge_id' => $charge->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $amount]);
    }

    $html = Livewire::test(RevenueChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertOk()->html();

    // 125.250 is the largest, so it is the full bar; 99.001 / 125.250 is 79.04% and 40.000 / 125.250 is 31.94%.
    expect(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and(($this->bars)($html))->toBe([
            ['label' => 'ENG-C', 'value' => '125.250 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'ENG-B', 'value' => '99.001 LYD', 'width' => '79', 'tone' => 'primary', 'set_apart' => false],
            ['label' => 'ENG-A', 'value' => '40.000 LYD', 'width' => '32', 'tone' => 'primary', 'set_apart' => false],
        ]);
});

it('writes the dates of the chosen range, not the month, for a day-based report', function () {
    $html = Livewire::test(RevenueChart::class, ['reportFilters' => ['from' => '2026-10-05', 'to' => '2026-10-20'], 'lazy' => false])->html();

    expect(($this->period)($html))->toBe('5 Oct 2026 – 20 Oct 2026');
});

it('draws the same rows as the table beneath it', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $course = Course::factory()->create();
    foreach (['ENG-A' => '40.000', 'ENG-B' => '99.001'] as $code => $amount) {
        $enrollment = Enrollment::factory()->for(Batch::factory()->for($course)->create(['code' => $code]))->create();
        $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey()]);
        $payment = Payment::factory()->create(['received_at' => '2026-10-05 10:00:00']);
        PaymentAllocation::factory()->create(['charge_id' => $charge->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $amount]);
    }

    $html = Livewire::test(RevenueReportPage::class)->html();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $tableCells = array_map(fn ($cell): string => trim($cell->textContent), iterator_to_array($document->querySelectorAll('tbody td')));

    expect(array_column(($this->bars)($html), 'label'))->toHaveCount(2)
        ->and($tableCells)->toContain('ENG-A', 'ENG-B', '40.000', '99.001');
});

it('says so, still naming the period, when the period has no data', function () {
    $html = Livewire::test(RevenueChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->html();

    expect(($this->bars)($html))->toBe([])
        ->and(($this->period)($html))->toBe('1 Oct 2026 – 31 Oct 2026')
        ->and($html)->toContain('No rows match these filters.');
});

it('redraws when its reactive filters change', function () {
    $property = new ReflectionProperty(ReportBarsWidget::class, 'reportFilters');

    expect($property->getAttributes(Reactive::class))->toHaveCount(1);
});

it('passes only applied filters to its mounted header chart', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $page = Livewire::test(RevenueReportPage::class)->assertSeeLivewire(RevenueChart::class);
    expect($page->instance()->getWidgetData()['reportFilters'] ?? null)->toBe($this->reportOptions);

    $edited = ['from' => '2026-09-01', 'to' => '2026-09-30'];
    $page->set('filters', $edited);
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($this->reportOptions);
    $page->call('applyFilters')->assertHasNoFormErrors();
    expect($page->instance()->getWidgetData()['reportFilters'])->toBe($edited);
});

it('updates the existing child chart only when the edited period is applied', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $enrollment = Enrollment::factory()->for(Batch::factory()->create(['code' => 'ENG-S']))->create();
    $charge = Charge::factory()->create(['enrollment_id' => $enrollment->getKey()]);
    foreach (['2026-10' => '100.125', '2026-09' => '75.001'] as $month => $amount) {
        $payment = Payment::factory()->create(['received_at' => $month.'-05 10:00:00']);
        PaymentAllocation::factory()->create(['charge_id' => $charge->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $amount]);
    }
    $page = Livewire::test(RevenueReportPage::class);
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
        ->and(($this->bars)($response->json('components.1.effects.html'))[0]['value'])->toBe('100.125 LYD');

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
            ['label' => 'ENG-S', 'value' => '75.001 LYD', 'width' => '100', 'tone' => 'primary', 'set_apart' => false],
        ])
        ->and(($this->period)($response->json('components.1.effects.html')))->toBe('1 Sep 2026 – 30 Sep 2026');
});

it('refuses an independently mounted chart without report permission', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));
    Livewire::test(RevenueChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an inactive report permission holder', function () {
    $this->viewer->forceFill(['is_active' => false])->saveQuietly();
    $this->actingAs($this->viewer->refresh());
    Livewire::test(RevenueChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});

it('refuses an unauthenticated chart request', function () {
    auth()->logout();
    Livewire::test(RevenueChart::class, ['reportFilters' => $this->reportOptions, 'lazy' => false])->assertForbidden();
});
