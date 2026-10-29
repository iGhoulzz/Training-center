<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryAdjustmentsHaveNoLineException;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Makes a monthly-salary DRAFT follow the compensation rates currently on file.
 *
 * THE RULE (owner decision, 2026-10-29)
 * -------------------------------------
 * A salary draft always follows the current rates; a finalized run never changes.
 * This is the one path that applies the rule, called from the review page when a
 * draft is opened and from `FinalizePayrollRunAction` under its lock, so what is
 * posted is exactly what the rates say at that moment.
 *
 * RECONCILE, NEVER REBUILD
 * ------------------------
 * A line is identified by (person, rate row, segment start, segment end). A line
 * the current rates still produce keeps its id; one whose figures differ is updated
 * in place; only a line that vanished is deleted and only a new segment is inserted.
 * That matters because `payroll_line_adjustments.payroll_line_id` cascades: deleting
 * and re-inserting every line would silently delete every bonus and deduction.
 *
 * Before a vanished line is deleted, each of its bonuses and deductions is moved
 * (amount, reason and author untouched) to that person's earliest line in the SAME
 * posting month, which is the grouping `WageCostReport` sums adjustments by, so a
 * person's cost per month is the same before and after. When no such line exists
 * the whole recalculation is refused with `SalaryAdjustmentsHaveNoLineException`
 * before anything is written.
 *
 * IDEMPOTENT: when the stored lines already equal what the rates say, nothing is
 * written and no activity row is filed. One activity row is filed on the run when
 * something did change.
 *
 * LOCK ORDER, shared with FinalizePayrollRunAction, ChangeCompensationAction and
 * CompensationPeriodInvariantService:
 *
 *   run row  ->  users rows, ascending id  ->  rate rows  ->  line rows  ->  adjustments
 *
 * `ChangeCompensationAction` takes the person's users row before it touches a rate,
 * so holding that row while the rates are read (a locking read, to bypass any older
 * REPEATABLE READ snapshot) means a rate change committing at the same moment is
 * either entirely seen or entirely not. The users locked are those of the existing
 * lines AND of every person whose salary rate intersects the period, because a
 * person who got a rate after the draft was opened has no line yet. Two openings of
 * the same page queue on the run row; the second then finds nothing to change.
 */
final class RecalculateSalaryDraftAction
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * Recalculate a draft for an actor allowed to create payroll runs.
     *
     * @return bool whether any stored line or adjustment changed
     *
     * @throws SalaryAdjustmentsHaveNoLineException
     */
    public function execute(User $actor, PayrollRun $run): bool
    {
        Gate::forUser($actor)->authorize('create', PayrollRun::class);

        return $this->causers->withCauser(
            $actor,
            fn (): bool => DB::transaction(function () use ($actor, $run): bool {
                $lockedRun = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

                return $this->recalculateLocked($actor, $lockedRun);
            }),
        );
    }

    /**
     * The recalculation itself, for a caller that has authorized the actor, opened
     * the transaction and locked the run row (FinalizePayrollRunAction does).
     *
     * @return bool whether any stored line or adjustment changed
     *
     * @throws SalaryAdjustmentsHaveNoLineException
     */
    public function recalculateLocked(User $actor, PayrollRun $lockedRun): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A salary draft is recalculated inside the transaction that holds its run lock.');
        }

        if ($lockedRun->type !== PayrollRunType::MonthlySalary || $lockedRun->isFinalized()) {
            return false;
        }

        $periodStart = CarbonImmutable::parse($lockedRun->period_start?->toDateString() ?? throw new LogicException('A salary run requires a period.'));
        $periodEnd = CarbonImmutable::parse($lockedRun->period_end?->toDateString() ?? throw new LogicException('A salary run requires a period.'));

        $userIds = PayrollLine::query()
            ->where('payroll_run_id', $lockedRun->getKey())
            ->pluck('user_id')
            ->merge($this->calculator->salarySegments($periodStart, $periodEnd)->pluck('user_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        User::withTrashed()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get();

        $computed = $this->calculator->salarySegments($periodStart, $periodEnd, lockRates: true)->values();

        /** @var Collection<int, PayrollLine> $stored */
        $stored = PayrollLine::query()
            ->where('payroll_run_id', $lockedRun->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /*
         * Locking, like the lines: a bonus committed by another session after this
         * transaction's snapshot was opened must be seen, or moving "every"
         * adjustment off a vanished line would miss it and the cascade would
         * delete it unseen.
         */
        $adjustments = PayrollLineAdjustment::query()
            ->whereIn('payroll_line_id', $stored->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('payroll_line_id');

        // Only well-formed salary lines are reconciled; any other shape is left for
        // the finalize shape check to refuse, exactly as before.
        $unmatched = $stored
            ->filter(fn (PayrollLine $line): bool => $line->segment_start !== null && $line->segment_end !== null)
            ->groupBy(fn (PayrollLine $line): string => $this->lineKey($line))
            ->map(fn ($group) => $group->values());

        /** @var array<int, PayrollLine> $matched computed index => existing line */
        $matched = [];

        foreach ($computed as $index => $segment) {
            $key = $this->segmentKey($segment);
            $candidate = $unmatched->get($key)?->shift();

            if ($candidate instanceof PayrollLine) {
                $matched[$index] = $candidate;
            }
        }

        $vanished = $unmatched->flatten(1);
        $updates = collect($matched)->filter(fn (PayrollLine $line, int $index): bool => $this->figuresDiffer($line, $computed[$index]));
        $creations = $computed->keys()->reject(fn (int $index): bool => isset($matched[$index]));

        if ($vanished->isEmpty() && $updates->isEmpty() && $creations->isEmpty()) {
            return false;
        }

        // Decide every adjustment's destination before writing a single row.
        $destinations = [];

        foreach ($vanished as $line) {
            if ($adjustments->get($line->getKey(), collect())->isEmpty()) {
                continue;
            }

            $month = CarbonImmutable::instance($line->segment_start)->format('Y-m');
            $targetIndex = $computed
                ->keys()
                ->filter(fn (int $index): bool => (int) $computed[$index]['user_id'] === (int) $line->user_id
                    && substr((string) $computed[$index]['segment_start'], 0, 7) === $month)
                ->sortBy(fn (int $index): string => (string) $computed[$index]['segment_start'])
                ->first();

            if ($targetIndex === null) {
                throw new SalaryAdjustmentsHaveNoLineException((int) $line->user_id, $month);
            }

            $destinations[$line->getKey()] = $targetIndex;
        }

        foreach ($updates as $index => $line) {
            $line->update($this->figures($computed[$index]));
        }

        $created = [];

        foreach ($creations as $index) {
            $created[$index] = PayrollLine::create([
                'payroll_run_id' => $lockedRun->getKey(),
                ...$this->figures($computed[$index]),
                'user_id' => (int) $computed[$index]['user_id'],
                'staff_compensation_id' => (int) $computed[$index]['staff_compensation_id'],
                'segment_start' => $computed[$index]['segment_start'],
                'segment_end' => $computed[$index]['segment_end'],
                'batch_instructor_id' => null,
                'corrects_payroll_line_id' => null,
                'frozen_hours' => null,
                'posting_period_start' => null,
                'reason' => null,
                'finalized_at' => null,
            ]);
        }

        $reattached = 0;

        foreach ($vanished as $line) {
            foreach ($adjustments->get($line->getKey(), collect()) as $adjustment) {
                $target = $matched[$destinations[$line->getKey()]] ?? $created[$destinations[$line->getKey()]];
                // Moved BEFORE the line is deleted: the foreign key cascades.
                $adjustment->update(['payroll_line_id' => $target->getKey()]);
                $reattached++;
            }

            // destroy() deletes through the model, so the removal is audited like any other.
            PayrollLine::destroy($line->getKey());
        }

        activity()
            ->causedBy($actor)
            ->performedOn($lockedRun)
            ->event(ActivityEvent::UPDATED)
            ->withProperties([
                'recalculation' => 'salary_lines_recalculated_from_current_rates',
                'lines_created' => count($created),
                'lines_updated' => $updates->count(),
                'lines_removed' => $vanished->count(),
                'adjustments_reattached' => $reattached,
            ])
            ->log(ActivityEvent::UPDATED);

        return true;
    }

    /** @param array<string, mixed> $segment */
    private function segmentKey(array $segment): string
    {
        return implode('|', [
            $segment['user_id'],
            $segment['staff_compensation_id'],
            $segment['segment_start'],
            $segment['segment_end'],
        ]);
    }

    private function lineKey(PayrollLine $line): string
    {
        return implode('|', [
            $line->user_id,
            $line->staff_compensation_id,
            CarbonImmutable::instance($line->segment_start)->toDateString(),
            CarbonImmutable::instance($line->segment_end)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    private function figures(array $segment): array
    {
        return [
            'frozen_rate' => $segment['frozen_rate'],
            'frozen_days' => $segment['frozen_days'],
            'frozen_days_in_month' => $segment['frozen_days_in_month'],
            'computed_amount' => $segment['computed_amount'],
        ];
    }

    /** @param array<string, mixed> $segment */
    private function figuresDiffer(PayrollLine $line, array $segment): bool
    {
        return (string) $line->frozen_rate !== (string) $segment['frozen_rate']
            || (int) $line->frozen_days !== (int) $segment['frozen_days']
            || (int) $line->frozen_days_in_month !== (int) $segment['frozen_days_in_month']
            || (string) $line->computed_amount !== (string) $segment['computed_amount'];
    }
}
