<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Writes a signed bonus or deduction while its line's run is still draft. */
final class AddPayrollLineAdjustmentAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(
        User $actor,
        PayrollLine $line,
        string $amount,
        string $reason,
    ): PayrollLineAdjustment {
        Gate::forUser($actor)->authorize('create', PayrollRun::class);

        $validated = Validator::make([
            'amount' => trim($amount),
            'reason' => trim($reason),
        ], [
            'amount' => ['required', 'numeric', 'decimal:0,3', 'gte:-999999999.999', 'lte:999999999.999'],
            'reason' => ['required', 'string'],
        ])->validate();
        $adjustment = Money::fromDecimal((string) $validated['amount']);

        if ($adjustment->isZero()) {
            throw ValidationException::withMessages([
                'amount' => __('payroll.non_zero_amount_required'),
            ]);
        }

        return $this->causers->withCauser(
            $actor,
            fn (): PayrollLineAdjustment => DB::transaction(function () use (
                $actor,
                $line,
                $adjustment,
                $validated,
            ): PayrollLineAdjustment {
                $lockedRun = PayrollRun::query()
                    ->whereKey($line->payroll_run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedLine = PayrollLine::query()
                    ->whereKey($line->getKey())
                    ->where('payroll_run_id', $lockedRun->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->isFinalized() || $lockedLine->isFinalized()) {
                    throw ValidationException::withMessages([
                        'line' => __('payroll.draft_line_required'),
                    ]);
                }

                return PayrollLineAdjustment::create([
                    'payroll_line_id' => $lockedLine->getKey(),
                    'amount' => $adjustment->toDecimal(),
                    'reason' => (string) $validated['reason'],
                    'created_by' => $actor->getKey(),
                ]);
            }),
        );
    }
}
