<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\OutstandingAgedReportExporter;
use App\Domain\Finance\Exports\ReportKind;

final class OutstandingAgedReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/outstanding-aged';

    protected static ?int $navigationSort = 41;

    public static function kind(): ReportKind
    {
        return ReportKind::OutstandingAged;
    }

    protected static function exporter(): string
    {
        return OutstandingAgedReportExporter::class;
    }
}
