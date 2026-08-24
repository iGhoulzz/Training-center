<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\DailyTenderReportExporter;
use App\Domain\Finance\Exports\ReportKind;

final class DailyTenderReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/daily-tender';

    protected static ?int $navigationSort = 43;

    public static function kind(): ReportKind
    {
        return ReportKind::DailyTender;
    }

    protected static function exporter(): string
    {
        return DailyTenderReportExporter::class;
    }
}
