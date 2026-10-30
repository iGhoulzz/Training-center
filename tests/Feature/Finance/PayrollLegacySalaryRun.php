<?php

declare(strict_types=1);

use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| A salary run of the kind the application no longer creates (P4-T40)
|--------------------------------------------------------------------------
|
| `CreatePayrollRunAction` now opens a salary run for exactly one calendar month,
| and only one per month. Several older tests prove behaviour of a run that is
| not that (a period over two months, a partial month, two runs over the same
| days). Such a run can still exist, because the rule arrived after runs did, and
| finalize, recalculation and the segment arithmetic still have to handle it. This
| builds one the way the Action used to: a draft row with the lines the calculator
| produces for its period.
|
| Required by the test files that need it, so the helper is defined once.
*/

if (! function_exists('legacySalaryRun')) {
    /**
     * @param  bool  $reverseLines  insert the lines in descending user-id order
     */
    function legacySalaryRun(User $creator, string $start, string $end, bool $reverseLines = false): PayrollRun
    {
        $run = PayrollRun::factory()->create([
            'period_start' => $start,
            'period_end' => $end,
            'created_by' => $creator->getKey(),
        ]);

        $segments = app(PayrollCalculator::class)->salarySegments(
            CarbonImmutable::createFromFormat('!Y-m-d', $start),
            CarbonImmutable::createFromFormat('!Y-m-d', $end),
        );

        foreach ($reverseLines ? $segments->sortByDesc('user_id') : $segments as $line) {
            PayrollLine::create([
                'payroll_run_id' => $run->getKey(),
                ...$line,
                'batch_instructor_id' => null,
                'corrects_payroll_line_id' => null,
                'frozen_hours' => null,
                'posting_period_start' => null,
                'reason' => null,
                'finalized_at' => null,
            ]);
        }

        return $run;
    }
}
