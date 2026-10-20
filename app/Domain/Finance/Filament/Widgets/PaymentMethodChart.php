<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Support\Money;

final class PaymentMethodChart extends ReportBarsWidget
{
    public function getHeading(): string
    {
        return __('reports.charts.payment_method');
    }

    protected function kind(): ReportKind
    {
        return ReportKind::PaymentMethod;
    }

    /** @return list<array{label: string, value: Money, tone: string}> */
    protected function rows(ReportDataset $dataset): array
    {
        return $this->largestFirst(array_map(static fn (array $row): array => [
            'label' => (string) $row['cells']['method'],
            'value' => Money::fromDecimal($row['cells']['total']),
            'tone' => 'primary',
        ], $dataset->rows));
    }
}
