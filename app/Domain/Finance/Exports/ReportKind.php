<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

/** The seven financial reports exposed by the admin panel. */
enum ReportKind: string
{
    case Revenue = 'revenue';
    case OutstandingAged = 'outstanding_aged';
    case PaymentMethod = 'payment_method';
    case DailyTender = 'daily_tender';
    case WageCost = 'wage_cost';
    case Profit = 'profit';
    case StudentPaymentHistory = 'student_payment_history';
}
