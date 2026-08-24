<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\PaymentMethodReportExporter;
use App\Domain\Finance\Exports\ReportKind;

final class PaymentMethodReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/payment-methods';

    protected static ?int $navigationSort = 42;

    public static function kind(): ReportKind
    {
        return ReportKind::PaymentMethod;
    }

    protected static function exporter(): string
    {
        return PaymentMethodReportExporter::class;
    }
}
