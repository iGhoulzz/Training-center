<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Models\User;

final class ProfitReportExporter extends ReportExporter
{
    protected static ?string $model = User::class;

    protected const COLUMNS = ['revenue', 'wage_cost', 'profit'];

    public static function kind(): ReportKind
    {
        return ReportKind::Profit;
    }
}
