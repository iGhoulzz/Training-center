<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Support\Money;

final class RevenueChart extends ReportBarsWidget
{
    public function getHeading(): string
    {
        return __('reports.charts.revenue');
    }

    protected function kind(): ReportKind
    {
        return ReportKind::Revenue;
    }

    /** @return list<array{label: string, value: Money, tone: string}> */
    protected function rows(ReportDataset $dataset): array
    {
        return $this->largestFirst(array_map(static fn (array $row): array => [
            'label' => (string) $row['cells']['batch_code'],
            'value' => Money::fromDecimal($row['cells']['batch_total']),
            'tone' => 'primary',
        ], $dataset->rows));
    }
}
