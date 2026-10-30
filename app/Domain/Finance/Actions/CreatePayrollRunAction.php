<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryMonthAlreadyHasRunException;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Opens a payroll draft and builds its reviewable provisional lines.
 *
 * A monthly salary run covers exactly one calendar month, and a month has at
 * most one such run, draft or finalized (P4-T40). The period must be the first
 * to the last day of one month; a month already covered, by overlap rather than
 * equality so that an older run of an odd period still blocks every month it
 * touches, is refused with `SalaryMonthAlreadyHasRunException`. The real guard
 * against two people creating one month at the same moment is the unique index
 * on `payroll_runs.salary_month`; its duplicate-key error is converted into the
 * same typed refusal here. Deleting a draft frees its month.
 */
final class CreatePayrollRunAction
{
    /** MySQL ER_DUP_ENTRY, checked beside the index name as the other lost-race handlers do. */
    private const DUPLICATE_ENTRY = 1062;

    /** Created by the add_salary_month_to_payroll_runs_table migration. */
    public const SALARY_MONTH_INDEX = 'uniq_salary_run_per_month';

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CauserResolver $causers,
    ) {}

    /** @param list<int> $assignmentIds */
    public function execute(
        User $actor,
        PayrollRunType $type,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        array $assignmentIds = [],
        ?string $notes = null,
    ): PayrollRun {
        Gate::forUser($actor)->authorize('create', PayrollRun::class);

        if ($type !== PayrollRunType::MonthlySalary) {
            return $this->createRun($actor, $type, null, null, $assignmentIds, $notes);
        }

        $validated = Validator::make([
            'period_start' => $periodStart === null ? null : trim($periodStart),
            'period_end' => $periodEnd === null ? null : trim($periodEnd),
        ], [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ])->validate();

        $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['period_start']);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['period_end']);
        assert($start instanceof CarbonImmutable);
        assert($end instanceof CarbonImmutable);

        if (! $start->isSameDay($start->startOfMonth()) || ! $end->isSameDay($start->endOfMonth())) {
            throw ValidationException::withMessages([
                'period_start' => __('payroll.salary_period_must_be_one_month'),
            ]);
        }

        try {
            return $this->createRun($actor, $type, $start, $end, $assignmentIds, $notes);
        } catch (UniqueConstraintViolationException $exception) {
            if (($exception->errorInfo[1] ?? null) !== self::DUPLICATE_ENTRY
                || $exception->index !== self::SALARY_MONTH_INDEX
            ) {
                throw $exception;
            }

            // A locking read, because inside a caller's transaction a plain read may
            // answer from a snapshot older than the run that just won the race.
            $existing = $this->overlappingSalaryRun($start, $end, locking: true);

            if (! $existing instanceof PayrollRun) {
                throw $exception;
            }

            throw new SalaryMonthAlreadyHasRunException((int) $existing->getKey(), $start);
        }
    }

    /** @param list<int> $assignmentIds */
    private function createRun(
        User $actor,
        PayrollRunType $type,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        array $assignmentIds,
        ?string $notes,
    ): PayrollRun {
        return $this->causers->withCauser(
            $actor,
            fn (): PayrollRun => DB::transaction(function () use (
                $actor,
                $type,
                $start,
                $end,
                $assignmentIds,
                $notes,
            ): PayrollRun {
                if ($start !== null && $end !== null) {
                    $existing = $this->overlappingSalaryRun($start, $end);

                    if ($existing instanceof PayrollRun) {
                        throw new SalaryMonthAlreadyHasRunException((int) $existing->getKey(), $start);
                    }
                }

                $run = PayrollRun::create([
                    'type' => $type,
                    'period_start' => $start?->toDateString(),
                    'period_end' => $end?->toDateString(),
                    'created_by' => $actor->getKey(),
                    'finalized_at' => null,
                    'finalized_by' => null,
                    'notes' => $notes,
                ]);

                $lines = match ($type) {
                    PayrollRunType::MonthlySalary => $this->calculator->salarySegments(
                        $start,
                        $end,
                    ),
                    PayrollRunType::InstructorBatch => $this->calculator->instructorLines($assignmentIds),
                    PayrollRunType::Adjustment => collect(),
                };

                foreach ($lines as $line) {
                    PayrollLine::create([
                        'payroll_run_id' => $run->getKey(),
                        ...$line,
                        'batch_instructor_id' => $line['batch_instructor_id'] ?? null,
                        'corrects_payroll_line_id' => null,
                        'segment_start' => $line['segment_start'] ?? null,
                        'segment_end' => $line['segment_end'] ?? null,
                        'frozen_hours' => $line['frozen_hours'] ?? null,
                        'frozen_days' => $line['frozen_days'] ?? null,
                        'frozen_days_in_month' => $line['frozen_days_in_month'] ?? null,
                        'posting_period_start' => null,
                        'reason' => null,
                        'finalized_at' => null,
                    ]);
                }

                return $run;
            }),
        );
    }

    /**
     * Any salary run, draft or finalized, whose period overlaps this one.
     *
     * Overlap, not equality: a run opened before the one-month rule may span
     * 1 October to 1 November, and it still blocks October and November.
     */
    private function overlappingSalaryRun(CarbonImmutable $start, CarbonImmutable $end, bool $locking = false): ?PayrollRun
    {
        $query = PayrollRun::query()
            ->where('type', PayrollRunType::MonthlySalary->value)
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->orderBy('id');

        if ($locking) {
            $query->sharedLock();
        }

        return $query->first();
    }
}
