<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Adds a flat correction to an adjustment draft without changing the original. */
final class AdjustPayrollLineAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(
        User $actor,
        PayrollRun $adjustmentRun,
        PayrollLine $target,
        string $amount,
        string $reason,
    ): PayrollLine {
        Gate::forUser($actor)->authorize('create', PayrollRun::class);

        $validated = Validator::make([
            'amount' => trim($amount),
            'reason' => trim($reason),
        ], [
            'amount' => ['required', 'numeric', 'decimal:0,3', 'gte:-999999999.999', 'lte:999999999.999'],
            'reason' => ['required', 'string'],
        ])->validate();
        $correction = Money::fromDecimal((string) $validated['amount']);

        if ($correction->isZero()) {
            throw ValidationException::withMessages([
                'amount' => __('payroll.non_zero_amount_required'),
            ]);
        }

        return $this->causers->withCauser(
            $actor,
            fn (): PayrollLine => DB::transaction(function () use (
                $adjustmentRun,
                $target,
                $correction,
                $validated,
            ): PayrollLine {
                $lockedRun = PayrollRun::query()
                    ->whereKey($adjustmentRun->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedTarget = PayrollLine::query()
                    ->whereKey($target->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->type !== PayrollRunType::Adjustment || $lockedRun->isFinalized()) {
                    $this->reject('run', __('payroll.adjustment_run_required'));
                }

                if (! $lockedTarget->isFinalized()) {
                    $this->reject('target', __('payroll.finalized_target_required'));
                }

                if ($lockedTarget->isCorrection()) {
                    $this->reject('target', __('payroll.correction_chain_forbidden'));
                }

                return PayrollLine::create([
                    'payroll_run_id' => $lockedRun->getKey(),
                    'user_id' => $lockedTarget->user_id,
                    'staff_compensation_id' => null,
                    'batch_instructor_id' => null,
                    'corrects_payroll_line_id' => $lockedTarget->getKey(),
                    'segment_start' => null,
                    'segment_end' => null,
                    'frozen_rate' => null,
                    'frozen_hours' => null,
                    'frozen_days' => null,
                    'frozen_days_in_month' => null,
                    'computed_amount' => $correction->toDecimal(),
                    'posting_period_start' => null,
                    'reason' => (string) $validated['reason'],
                    'finalized_at' => null,
                ]);
            }),
        );
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
