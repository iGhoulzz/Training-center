<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Finance\Models\Charge;

final class StudentPaymentHistoryExporter extends ReportExporter
{
    protected static ?string $model = Charge::class;

    protected const COLUMNS = [
        'student_code', 'student_name', 'charge_reference', 'charge_amount',
        'due_date', 'written_off', 'receipt_references', 'receipt_amounts', 'collected_total',
    ];

    public static function kind(): ReportKind
    {
        return ReportKind::StudentPaymentHistory;
    }
}
