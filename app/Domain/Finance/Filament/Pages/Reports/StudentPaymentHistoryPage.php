<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\StudentPaymentHistoryExporter;

final class StudentPaymentHistoryPage extends ReportPage
{
    protected static ?string $slug = 'reports/student-payment-history';

    protected static ?int $navigationSort = 46;

    public static function kind(): ReportKind
    {
        return ReportKind::StudentPaymentHistory;
    }

    protected static function exporter(): string
    {
        return StudentPaymentHistoryExporter::class;
    }
}
