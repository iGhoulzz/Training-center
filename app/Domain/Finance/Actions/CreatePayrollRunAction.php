<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

/** Opens a payroll draft and builds its reviewable provisional lines. */
final class CreatePayrollRunAction
{
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

        $start = null;
        $end = null;

        if ($type === PayrollRunType::MonthlySalary) {
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
        }

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
}
