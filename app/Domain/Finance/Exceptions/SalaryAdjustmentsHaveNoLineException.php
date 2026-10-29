<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A salary draft cannot follow the current rates without losing a bonus or deduction.
 *
 * RAISED BY `RecalculateSalaryDraftAction`, BEFORE IT HAS WRITTEN ANYTHING. A draft
 * line that the current rates no longer produce is removed, and the bonuses and
 * deductions on it are moved to that person's earliest line in the same posting month
 * first (`payroll_line_adjustments.payroll_line_id` cascades, so deleting the line
 * first would delete them silently). When the person has no line left in that month
 * there is nowhere to move them to, and the one safe answer is to change nothing and
 * say so: the operator decides, rather than the system dropping or re-dating money.
 *
 * The same refusal stops a finalize, which recalculates under its lock first.
 */
final class SalaryAdjustmentsHaveNoLineException extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly string $month)
    {
        parent::__construct(__('payroll.salary_adjustments_have_no_line'));
    }
}
