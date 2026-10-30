<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A calendar month has at most one monthly salary run, draft or finalized.
 *
 * Carries the run that already covers the month, so the refusal can name it and
 * the user can open it instead. Thrown by `CreatePayrollRunAction` both when its
 * own check finds an overlapping run and when the unique index on
 * `payroll_runs.salary_month` refuses a creation that lost a race.
 */
final class SalaryMonthAlreadyHasRunException extends RuntimeException
{
    public function __construct(
        public readonly int $existingRunId,
        public readonly CarbonImmutable $month,
    ) {
        parent::__construct(__('payroll.salary_month_already_has_run', [
            'month' => $month->translatedFormat('F Y'),
            'run' => $existingRunId,
        ]));
    }
}
