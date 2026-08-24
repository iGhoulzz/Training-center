<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Finance\Models\Charge;

final class OutstandingAgedReportExporter extends ReportExporter
{
    protected static ?string $model = Charge::class;

    protected const COLUMNS = [
        'charge_reference', 'student_code', 'student_name', 'due_date',
        'days_past_due', 'bucket', 'outstanding',
    ];

    public static function kind(): ReportKind
    {
        return ReportKind::OutstandingAged;
    }
}
