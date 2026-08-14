<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Seals a payroll draft under deterministic employee locks. */
final class FinalizePayrollRunAction
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CauserResolver $causers,
    ) {}

    public function execute(User $actor, PayrollRun $run): PayrollRun
    {
        Gate::forUser($actor)->authorize('finalize', $run);

        return $this->causers->withCauser(
            $actor,
            fn (): PayrollRun => DB::transaction(function () use ($actor, $run): PayrollRun {
                $lockedRun = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('finalize', $lockedRun);

                if ($lockedRun->isFinalized()) {
                    $this->reject('run', __('payroll.run_already_finalized'));
                }

                /** @var Collection<int, PayrollLine> $lines */
                $lines = PayrollLine::query()
                    ->where('payroll_run_id', $lockedRun->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $this->assertShapesMatch($lockedRun->type, $lines);

                $userIds = $lines->pluck('user_id')->unique()->sort()->values();
                User::withTrashed()
                    ->whereKey($userIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($lockedRun->type === PayrollRunType::MonthlySalary) {
                    $this->assertSalarySegmentsDoNotOverlap($lockedRun, $lines);
                }

                $finalizedAt = CarbonImmutable::instance(now());
                $instructorPeriod = CentreCalendar::localise($finalizedAt)->startOfMonth()->toDateString();
                $instructorFigures = $lockedRun->type === PayrollRunType::InstructorBatch
                    ? $this->calculator->instructorLines(
                        $lines->pluck('batch_instructor_id')->filter()->map(fn ($id): int => (int) $id)->all(),
                    )->keyBy('batch_instructor_id')
                    : collect();

                foreach ($lines as $line) {
                    $postingPeriod = match ($lockedRun->type) {
                        PayrollRunType::MonthlySalary => CarbonImmutable::instance(
                            $line->segment_start ?? throw new \LogicException('A salary line requires a segment.'),
                        )->startOfMonth()->toDateString(),
                        PayrollRunType::InstructorBatch => $instructorPeriod,
                        PayrollRunType::Adjustment => $line->corrects()
                            ->value('posting_period_start'),
                    };

                    if ($postingPeriod === null) {
                        $this->reject('lines', __('payroll.posting_period_missing'));
                    }

                    $attributes = [
                        'posting_period_start' => $postingPeriod,
                        'finalized_at' => $finalizedAt,
                    ];

                    if ($lockedRun->type === PayrollRunType::InstructorBatch) {
                        $figures = $instructorFigures->get($line->batch_instructor_id);

                        if (! is_array($figures) || $figures['user_id'] !== $line->user_id) {
                            $this->reject('lines', __('payroll.assignment_unavailable'));
                        }

                        $attributes = [
                            ...$attributes,
                            'staff_compensation_id' => $figures['staff_compensation_id'],
                            'frozen_rate' => $figures['frozen_rate'],
                            'frozen_hours' => $figures['frozen_hours'],
                            'computed_amount' => $figures['computed_amount'],
                        ];
                    }

                    $line->update($attributes);
                }

                $lockedRun->update([
                    'finalized_at' => $finalizedAt,
                    'finalized_by' => $actor->getKey(),
                ]);

                return $lockedRun->refresh();
            }),
        );
    }

    /** @param Collection<int, PayrollLine> $lines */
    private function assertShapesMatch(PayrollRunType $type, Collection $lines): void
    {
        $matches = $lines->every(fn (PayrollLine $line): bool => match ($type) {
            PayrollRunType::MonthlySalary => $line->isSalaryLine(),
            PayrollRunType::InstructorBatch => $line->isInstructorLine(),
            PayrollRunType::Adjustment => $line->isCorrection(),
        });

        if (! $matches) {
            $this->reject('lines', __('payroll.line_shape_mismatch'));
        }
    }

    /** @param Collection<int, PayrollLine> $lines */
    private function assertSalarySegmentsDoNotOverlap(PayrollRun $run, Collection $lines): void
    {
        foreach ($lines as $line) {
            $overlap = PayrollLine::query()
                ->finalized()
                ->where('payroll_run_id', '!=', $run->getKey())
                ->where('user_id', $line->user_id)
                ->whereNotNull('segment_start')
                ->whereDate('segment_start', '<=', $line->segment_end?->toDateString())
                ->whereDate('segment_end', '>=', $line->segment_start?->toDateString())
                ->exists();

            if ($overlap) {
                $this->reject('lines', __('payroll.salary_segment_overlap'));
            }
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
