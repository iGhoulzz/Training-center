<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Enrollment\Models\Batch;

final class RevenueReportExporter extends ReportExporter
{
    protected static ?string $model = Batch::class;

    protected const COLUMNS = ['course_code', 'course_total', 'batch_code', 'batch_total'];

    public static function kind(): ReportKind
    {
        return ReportKind::Revenue;
    }
}
