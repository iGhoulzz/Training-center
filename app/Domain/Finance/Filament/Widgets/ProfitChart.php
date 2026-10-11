<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Pages\Reports\ReportPage;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;

final class ProfitChart extends ChartWidget
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
        return __('reports.charts.profit');
    }

    public function getDescription(): string
    {
        return __('reports.profit_definition');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $dataset = app(ReportDataBuilder::class)->build(ReportKind::Profit, $this->reportFilters, $user);

        $cells = array_column($dataset->rows, 'cells');

        return [
            'labels' => [__('reports.charts.period', [
                'from' => $this->reportFilters['from'],
                'to' => $this->reportFilters['to'],
            ])],
            'datasets' => [[
                'label' => $dataset->columns['profit'],
                'data' => array_column($cells, 'profit'),
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
