<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\RevenueReportExporter;

final class RevenueReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/revenue';

    protected static ?int $navigationSort = 40;

    public static function kind(): ReportKind
    {
        return ReportKind::Revenue;
    }

    protected static function exporter(): string
    {
        return RevenueReportExporter::class;
    }
}
