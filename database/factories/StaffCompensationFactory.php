<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffCompensation>
 */
class StaffCompensationFactory extends Factory
{
    protected $model = StaffCompensation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            /*
             * Salary, the type a `monthly_salary` run reads and the one design
             * section 7's pro-rating applies to. hourly() is the other; `per_student`
             * is gone and design section 15 records the removal so it is not
             * reintroduced as a missing case.
             */
            'type' => CompensationType::Salary,

            /*
             * A monthly amount, as a decimal STRING. `CHECK (amount > 0)` —
             * nobody is paid nothing, and a negative rate would be a deduction,
             * which is a `payroll_line_adjustments` row rather than a rate.
             */
            'amount' => '2500.000',

            /*
             * The start of a month a year back, on the centre's calendar rather
             * than UTC. A date, because a rate applies from a day and the salary
             * segmentation intersects this range with calendar months — whole-day
             * arithmetic with no instant to convert.
             *
             * Comfortably in the past so a run over any recent period finds this
             * row in force rather than not yet started.
             */
            'effective_from' => CentreCalendar::localise(now())
                ->subYear()
                ->startOfMonth()
                ->toDateString(),

            /*
             * NULL — the open current row, the one in force.
             * `ChangeCompensationAction` closes it as it inserts the next, and a
             * fixture that shipped closed would describe a rate nobody is on.
             * `CHECK (effective_to IS NULL OR effective_to >= effective_from)` is
             * satisfied by the null.
             */
            'effective_to' => null,
        ];
    }

    /**
     * A per-hour rate, which an `instructor_batch` run multiplies by frozen hours.
     *
     * The amount moves with the type on purpose: 2500.000 an hour is not a
     * plausible fixture, and getting these two out of step is exactly the mistake
     * the column's missing default exists to prevent — it pays somebody a monthly
     * salary as an hourly rate.
     */
    public function hourly(): static
    {
        return $this->state(fn (): array => [
            'type' => CompensationType::Hourly,
            'amount' => '35.000',
        ]);
    }

    /**
     * A superseded rate, closed off the day before its replacement began.
     *
     * What `ChangeCompensationAction` leaves behind when somebody gets a raise —
     * the row is never overwritten, which is a project non-negotiable. The close
     * date is derived from whatever `effective_from` this row ended up with, so
     * the range satisfies `staff_compensation_effective_to_not_before_from` even
     * when the caller supplied its own start date.
     *
     * No timezone conversion here, and that is not an oversight: both ends are
     * `date` columns with no instant behind them, so there is nothing to read on
     * a calendar. CentreCalendar appears above only because `effective_from`
     * derives from `now()`, which genuinely is an instant.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'effective_to' => CarbonImmutable::parse($attributes['effective_from'])
                ->addMonths(6)
                ->toDateString(),
        ]);
    }
}
