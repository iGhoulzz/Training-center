<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Models\User;

/** Defines the complete account and ability boundary for financial report exports. */
final class ReportExportAuthorization
{
    public static function allows(?User $requester): bool
    {
        return $requester instanceof User
            && $requester->is_active
            && $requester->can('view_financial_report')
            && $requester->can('export_financial_report');
    }
}
