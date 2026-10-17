<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ProfitReportExporter;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Widgets\ProfitChart;

final class ProfitReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/profit';

    protected static ?int $navigationSort = 45;

    public static function kind(): ReportKind
    {
        return ReportKind::Profit;
    }

    /**
     * Profit states what it means where it is read (spec section 8).
     *
     * The definition gained a term when expenses joined it, so the figure's
     * meaning changed between months without the number saying so. The title
     * and the shared period-summary panel use the same translated definition.
     */
    public function getSubheading(): string
    {
        return (string) __('reports.profit_definition');
    }

    /** @return list<class-string<ProfitChart>> */
    protected function getHeaderWidgets(): array
    {
        return [ProfitChart::class];
    }

    /** @return array<string, mixed> */
    public function getWidgetData(): array
    {
        return ['reportFilters' => $this->appliedFilters];
    }

    protected static function exporter(): string
    {
        return ProfitReportExporter::class;
    }
}
