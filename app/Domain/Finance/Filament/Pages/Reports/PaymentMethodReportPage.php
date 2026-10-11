<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\PaymentMethodReportExporter;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Widgets\PaymentMethodChart;

final class PaymentMethodReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/payment-methods';

    protected static ?int $navigationSort = 42;

    public static function kind(): ReportKind
    {
        return ReportKind::PaymentMethod;
    }

    /** @return list<class-string<PaymentMethodChart>> */
    protected function getHeaderWidgets(): array
    {
        return [PaymentMethodChart::class];
    }

    /** @return array<string, mixed> */
    public function getWidgetData(): array
    {
        return ['reportFilters' => $this->appliedFilters];
    }

    protected static function exporter(): string
    {
        return PaymentMethodReportExporter::class;
    }
}
