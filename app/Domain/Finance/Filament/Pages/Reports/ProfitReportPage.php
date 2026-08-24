<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ProfitReportExporter;
use App\Domain\Finance\Exports\ReportKind;

final class ProfitReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/profit';

    protected static ?int $navigationSort = 45;

    public static function kind(): ReportKind
    {
        return ReportKind::Profit;
    }

    protected static function exporter(): string
    {
        return ProfitReportExporter::class;
    }
}
