<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Models\User;

final class WageCostReportExporter extends ReportExporter
{
    protected static ?string $model = User::class;

    protected const COLUMNS = ['staff_name', 'total'];

    public static function kind(): ReportKind
    {
        return ReportKind::WageCost;
    }
}
