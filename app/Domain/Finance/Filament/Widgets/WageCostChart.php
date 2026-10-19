<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Support\Money;

final class WageCostChart extends ReportBarsWidget
{
    public function getHeading(): string
    {
        return __('reports.charts.wage_cost');
    }

    protected function kind(): ReportKind
    {
        return ReportKind::WageCost;
    }

    /** @return list<array{label: string, value: Money, tone: string}> */
    protected function rows(ReportDataset $dataset): array
    {
        return $this->largestFirst(array_map(static fn (array $row): array => [
            'label' => (string) $row['cells']['staff_name'],
            'value' => Money::fromDecimal($row['cells']['total']),
            'tone' => 'primary',
        ], $dataset->rows));
    }
}
