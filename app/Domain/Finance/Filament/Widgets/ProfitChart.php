<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Support\Money;

/**
 * Profit as a breakdown: collected revenue, wage cost, expenses, then the
 * result. The four values are the Profit dataset's own cells; nothing is
 * computed here beyond the sign a cost is drawn with.
 */
final class ProfitChart extends ReportBarsWidget
{
    public function getHeading(): string
    {
        return __('reports.charts.profit');
    }

    public function getDescription(): string
    {
        return __('reports.profit_definition');
    }

    protected function kind(): ReportKind
    {
        return ReportKind::Profit;
    }

    protected function isSigned(): bool
    {
        return true;
    }

    /** @return list<array{label: string, value: Money, tone: string, emphasis?: bool}> */
    protected function rows(ReportDataset $dataset): array
    {
        $cells = $dataset->rows[0]['cells'];
        $result = Money::fromDecimal($cells['profit']);

        return [
            [
                'label' => __('reports.charts.bars.revenue'),
                'value' => Money::fromDecimal($cells['revenue']),
                'tone' => 'success',
            ],
            [
                'label' => __('reports.charts.bars.wage_cost'),
                'value' => Money::zero()->subtract(Money::fromDecimal($cells['wage_cost'])),
                'tone' => 'danger',
            ],
            [
                'label' => __('reports.charts.bars.expenses'),
                'value' => Money::zero()->subtract(Money::fromDecimal($cells['expenses'])),
                'tone' => 'danger',
            ],
            [
                'label' => $result->isNegative() ? __('reports.charts.bars.loss') : __('reports.charts.bars.profit'),
                'value' => $result,
                'tone' => $result->isNegative() ? 'danger' : 'success',
                'emphasis' => true,
            ],
        ];
    }
}
