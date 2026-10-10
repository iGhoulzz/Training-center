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

    /**
     * Profit states what it means where it is read (spec section 8).
     *
     * The definition gained a term when expenses joined it, so the figure's
     * meaning changed between months without the number saying so. Shown under
     * the title, from a translation key, and nowhere else on this page: the
     * shared report shell and view are untouched.
     */
    public function getSubheading(): string
    {
        return (string) __('reports.profit_definition');
    }

    protected static function exporter(): string
    {
        return ProfitReportExporter::class;
    }
}
