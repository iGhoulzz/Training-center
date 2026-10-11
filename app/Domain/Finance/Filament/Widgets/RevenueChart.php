<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Pages\Reports\ReportPage;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;

final class RevenueChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    /** @var array<string, mixed> */
    #[Reactive]
    public array $reportFilters = [];

    public static function canView(): bool
    {
        return ReportPage::canAccess();
    }

    public function getHeading(): string
    {
        return __('reports.charts.revenue');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $dataset = app(ReportDataBuilder::class)->build(ReportKind::Revenue, $this->reportFilters, $user);

        if ($dataset->rows === []) {
            return [];
        }

        $cells = array_column($dataset->rows, 'cells');

        return [
            'labels' => array_column($cells, 'batch_code'),
            'datasets' => [[
                'label' => $dataset->columns['batch_total'],
                'data' => array_column($cells, 'batch_total'),
            ]],
        ];
    }

    public function getEmptyStateHeading(): string
    {
        return __('reports.empty');
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
