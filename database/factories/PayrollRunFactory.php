<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * Last month, whole, read on the centre's calendar. `now()` is an instant
         * and which month it falls in is a local question — a run opened at 00:30
         * local on 1 February covers January to the person opening it, while UTC
         * still says it is January and the previous month is December.
         */
        $lastMonth = CentreCalendar::localise(now())->subMonth();

        return [
            /*
             * A salary run, which is the type that carries a period.
             * `payroll_runs_period_matches_type` requires both dates for this
             * type and neither for the other two, so the type and the period
             * columns can never be set independently — instructorBatch() and
             * adjustment() null them as they switch.
             */
            'type' => PayrollRunType::MonthlySalary,

            'period_start' => $lastMonth->startOfMonth()->toDateString(),
            'period_end' => $lastMonth->endOfMonth()->toDateString(),

            'created_by' => User::factory(),

            /*
             * A DRAFT. Both finalization columns null together, which
             * `payroll_runs_finalization_columns_paired` requires — an approval
             * with no approver is not a state this table can hold. Draft is also
             * the only state in which a run is deletable or can take an
             * adjustment, so it is the one most tests start from.
             */
            'finalized_at' => null,
            'finalized_by' => null,

            'notes' => null,
        ];
    }

    /**
     * An on-demand run paying instructor-hour assignments, which carries NO period.
     *
     * Both dates must go, or `payroll_runs_period_matches_type` refuses the row.
     * That is not a formality: `batch_instructor.assigned_hours` is per batch
     * while a period is per calendar, so a 30-hour batch running January to March
     * has no defined January figure and a period here would be a number nothing
     * would ever honour.
     */
    public function instructorBatch(): static
    {
        return $this->state(fn (): array => [
            'type' => PayrollRunType::InstructorBatch,
            'period_start' => null,
            'period_end' => null,
        ]);
    }

    /**
     * A run that corrects lines in an already-finalized run. Also no period.
     *
     * Its lines post to **the corrected line's** period, not to this run's, which
     * is what makes a June correction land in March's wage cost.
     */
    public function adjustment(): static
    {
        return $this->state(fn (): array => [
            'type' => PayrollRunType::Adjustment,
            'period_start' => null,
            'period_end' => null,
        ]);
    }

    /**
     * Posted to the books — which is not the same as paid.
     *
     * Finalizing approves a run and posts it; disbursement is out of scope for
     * the entire project and there is no paid flag to set. Both columns move
     * together, per `payroll_runs_finalization_columns_paired`.
     *
     * **This does not finalize the run's lines.** A line carries its own
     * denormalized `finalized_at`, because the generated columns that prevent
     * paying twice live on the line and cannot read another table. Use
     * `PayrollLineFactory::finalized()` for those.
     */
    public function finalized(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'finalized_at' => now(),
            'finalized_by' => $actor?->getKey() ?? User::factory(),
        ]);
    }
}
