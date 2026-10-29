<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Actions\AddPayrollLineAdjustmentAction;
use App\Domain\Finance\Actions\AdjustPayrollLineAction;
use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Staff\Actions\DeactivateUserAction;
use Carbon\CarbonImmutable;

/**
 * Wages: rates that change over time, monthly salary runs, an instructor run, a
 * correction, and two drafts left for the owner to review and finalize.
 *
 * Everything goes through the Actions the Payroll and Staff compensation screens call
 * (ChangeCompensationAction, CreatePayrollRunAction, AddPayrollLineAdjustmentAction,
 * FinalizePayrollRunAction, AdjustPayrollLineAction), run by the owner because
 * run_payroll, finalize_payroll and create_staff_compensation are super-admin
 * abilities. Rates are effective-dated: a raise inserts a row and closes the previous
 * one, it never rewrites it.
 *
 * What the history is built to show:
 *
 * - three salaried employees with a monthly salary from the start of the window;
 * - one raised on the 16th of the month THREE months ago, so that month's finalized run
 *   carries two segments for her, and one raised effective the 16th of the CURRENT
 *   month, so the current draft carries two segments for him;
 * - hourly rates for the instructors, one of which changes once;
 * - the four complete months before this one finalized, some with a bonus or a deduction
 *   added to a line first;
 * - one instructor run covering every finished batch, finalized;
 * - one Adjustment run correcting a line of the oldest finalized salary run;
 * - a draft salary run for the current month and a draft instructor run for assignments
 *   on running batches, both left unfinalized.
 *
 * A departed instructor is also here: Hana is paid in the instructor run and then
 * deactivated, so the payroll history of someone who has left is visible too.
 */
final class DemoPayrollSeeder extends DemoAreaSeeder
{
    /** @var array<string, string> account key => starting monthly salary */
    private const SALARIES = ['rania' => '1800.000', 'tarek' => '2200.000', 'huda' => '1500.000'];

    /** @var array<string, string> account key => starting hourly rate */
    private const HOURLY = ['omar' => '35.000', 'salma' => '40.000', 'yousef' => '28.000', 'hana' => '30.000'];

    /**
     * Bonuses (positive) and deductions (negative) added to a draft line before it is finalized.
     *
     * @var array<int, list<array{string, string, string}>> months from now => [account, amount, reason]
     */
    private const MONTH_ADJUSTMENTS = [
        -4 => [['huda', '150.000', 'Quarterly attendance bonus']],
        -3 => [['tarek', '-75.000', 'Unpaid leave: two days']],
        -2 => [['rania', '200.000', 'Project completion bonus'], ['huda', '-40.000', 'Replacement of a damaged projector cable']],
        -1 => [['tarek', '100.000', 'Overtime during enrolment week']],
    ];

    public function run(): void
    {
        $this->registerRates();

        foreach ([-4, -3, -2, -1] as $month) {
            $this->registerSalaryRun($month);
        }

        $this->registerInstructorRun();
        $this->registerAdjustmentRun();
        $this->registerDrafts();
        $this->registerDeparture();
    }

    private function registerRates(): void
    {
        $context = $this->context;
        $start = $context->monthStart(-5)->toDateString();

        $context->timeline->add($context->day(-183), 11, 'starting rates', function () use ($context, $start): void {
            foreach (self::SALARIES as $key => $amount) {
                app(ChangeCompensationAction::class)->execute($context->user('owner'), (int) $context->user($key)->getKey(), CompensationType::Salary, $amount, $start);
            }

            foreach (self::HOURLY as $key => $amount) {
                app(ChangeCompensationAction::class)->execute($context->user('owner'), (int) $context->user($key)->getKey(), CompensationType::Hourly, $amount, $start);
            }
        });

        // Raised on the 16th of the month three months ago: that month's run is split in two.
        $this->registerRaise('rania', CompensationType::Salary, '1950.000', $context->monthStart(-3)->addDays(9), $context->monthStart(-3)->addDays(15));

        // Raised effective the 16th of THIS month: the current draft is split in two.
        $this->registerRaise('tarek', CompensationType::Salary, '2350.000', $context->day(-3), $context->monthStart(0)->addDays(15));

        // The instructor rate that changes once, 50 days ago.
        $this->registerRaise('omar', CompensationType::Hourly, '40.000', $context->day(-52), $context->day(-50));
    }

    private function registerRaise(string $account, CompensationType $type, string $amount, CarbonImmutable $recordedOn, CarbonImmutable $effective): void
    {
        $context = $this->context;

        $context->timeline->add($this->clamp($recordedOn), 10, "raise for {$account}", function () use ($context, $account, $type, $amount, $effective): void {
            app(ChangeCompensationAction::class)->execute(
                $context->user('owner'),
                (int) $context->user($account)->getKey(),
                $type,
                $amount,
                $effective->toDateString(),
            );
        });
    }

    private function registerSalaryRun(int $month): void
    {
        $context = $this->context;
        $first = $context->monthStart($month);
        $last = $first->endOfMonth()->startOfDay();
        $key = "salary{$month}";

        $context->timeline->add($this->clamp($last->addDays(2)), 13, "create the salary run for {$first->format('F Y')}", function () use ($context, $key, $first, $last): void {
            $context->payrollRuns[$key] = app(CreatePayrollRunAction::class)->execute(
                $context->user('owner'),
                PayrollRunType::MonthlySalary,
                $first->toDateString(),
                $last->toDateString(),
                [],
                'Salaries for '.$first->format('F Y'),
            );
        });

        foreach (self::MONTH_ADJUSTMENTS[$month] as [$account, $amount, $reason]) {
            $context->timeline->add($this->clamp($last->addDays(2)), 13, "adjust {$account} in {$key}", function () use ($context, $key, $account, $amount, $reason): void {
                app(AddPayrollLineAdjustmentAction::class)->execute(
                    $context->user('owner'),
                    $this->lineOf($key, $account),
                    $amount,
                    $reason,
                );
            });
        }

        $context->timeline->add($this->clamp($last->addDays(3)), 13, "finalize {$key}", function () use ($context, $key): void {
            app(FinalizePayrollRunAction::class)->execute($context->user('owner'), $context->payrollRuns[$key]);
        });
    }

    private function registerInstructorRun(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-70), 13, 'create the instructor run for the finished batches', function () use ($context): void {
            $assignments = [];

            foreach (['F1', 'F2', 'F3'] as $batch) {
                foreach (app(EnrollmentQueryService::class)->instructorAssignmentsFor((int) $context->batches[$batch]->getKey()) as $assignment) {
                    $assignments[] = $assignment['id'];
                }
            }

            $context->payrollRuns['instructors'] = app(CreatePayrollRunAction::class)->execute(
                $context->user('owner'),
                PayrollRunType::InstructorBatch,
                null,
                null,
                $assignments,
                'Teaching hours for the three finished batches',
            );
        });

        $context->timeline->add($context->day(-69), 13, 'finalize the instructor run', function () use ($context): void {
            app(FinalizePayrollRunAction::class)->execute($context->user('owner'), $context->payrollRuns['instructors']);
        });
    }

    /** A correction to a line of the oldest finalized salary run, in a run of its own. */
    private function registerAdjustmentRun(): void
    {
        $context = $this->context;
        $day = $this->clamp($context->monthStart(-2)->addDays(7));

        $context->timeline->add($day, 13, 'create the adjustment run', function () use ($context): void {
            $run = app(CreatePayrollRunAction::class)->execute(
                $context->user('owner'),
                PayrollRunType::Adjustment,
                null,
                null,
                [],
                'Correction to an earlier salary run',
            );

            app(AdjustPayrollLineAction::class)->execute(
                $context->user('owner'),
                $run,
                $this->lineOf('salary-4', 'rania'),
                '120.000',
                'Overtime hours were left out of the original run.',
            );

            $context->payrollRuns['adjustment'] = $run;
        });

        $context->timeline->add($this->clamp($day->addDay()), 13, 'finalize the adjustment run', function () use ($context): void {
            app(FinalizePayrollRunAction::class)->execute($context->user('owner'), $context->payrollRuns['adjustment']);
        });
    }

    /** Two runs left as drafts, so the owner can open, review and finalize them. */
    private function registerDrafts(): void
    {
        $context = $this->context;
        $first = $context->monthStart(0);

        $context->timeline->add($context->day(-1), 13, 'draft the salary run for this month', function () use ($context, $first): void {
            $context->payrollRuns['draft-salary'] = app(CreatePayrollRunAction::class)->execute(
                $context->user('owner'),
                PayrollRunType::MonthlySalary,
                $first->toDateString(),
                $first->endOfMonth()->startOfDay()->toDateString(),
                [],
                'Salaries for '.$first->format('F Y').' (draft)',
            );

            app(AddPayrollLineAdjustmentAction::class)->execute(
                $context->user('owner'),
                $this->lineOf('draft-salary', 'rania'),
                '100.000',
                'Mid-month performance bonus',
            );

            app(AddPayrollLineAdjustmentAction::class)->execute(
                $context->user('owner'),
                $this->lineOf('draft-salary', 'huda'),
                '-60.000',
                'Late arrivals this month',
            );
        });

        $context->timeline->add($context->day(-1), 14, 'draft the instructor run for the running batches', function () use ($context): void {
            $wanted = ['R1' => 'yousef', 'R2' => 'omar', 'R3' => 'salma'];
            $assignments = [];

            foreach ($wanted as $batch => $account) {
                foreach (app(EnrollmentQueryService::class)->instructorAssignmentsFor((int) $context->batches[$batch]->getKey()) as $assignment) {
                    if ($assignment['user_id'] === (int) $context->user($account)->getKey()) {
                        $assignments[] = $assignment['id'];
                    }
                }
            }

            $context->payrollRuns['draft-instructors'] = app(CreatePayrollRunAction::class)->execute(
                $context->user('owner'),
                PayrollRunType::InstructorBatch,
                null,
                null,
                $assignments,
                'Teaching hours so far on the running batches (draft)',
            );
        });
    }

    private function registerDeparture(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-30), 12, 'Hana leaves the centre', function () use ($context): void {
            app(DeactivateUserAction::class)->execute($context->user('owner'), $context->user('hana'));
        });
    }

    /** An event is never dated after today. */
    private function clamp(CarbonImmutable $day): CarbonImmutable
    {
        return $day->greaterThan($this->context->today) ? $this->context->today : $day;
    }

    /**
     * The employee's last segment line in a run, the one a bonus or correction attaches to.
     */
    private function lineOf(string $runKey, string $account): PayrollLine
    {
        $line = PayrollLine::query()
            ->where('payroll_run_id', $this->context->payrollRuns[$runKey]->getKey())
            ->where('user_id', $this->context->user($account)->getKey())
            ->orderBy('segment_start')
            ->orderBy('id')
            ->get()
            ->last();

        return $line ?? throw new \LogicException("Run [{$runKey}] has no line for [{$account}].");
    }
}
