<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Finance\Models\PaymentTender;

final class DailyTenderReportExporter extends ReportExporter
{
    protected static ?string $model = PaymentTender::class;

    protected const COLUMNS = ['method', 'total'];

    public static function kind(): ReportKind
    {
        return ReportKind::DailyTender;
    }
}
