<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\WageCostReportExporter;

final class WageCostReportPage extends ReportPage
{
    protected static ?string $slug = 'reports/wage-cost';

    protected static ?int $navigationSort = 44;

    public static function kind(): ReportKind
    {
        return ReportKind::WageCost;
    }

    protected static function exporter(): string
    {
        return WageCostReportExporter::class;
    }
}
