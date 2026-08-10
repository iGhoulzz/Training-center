<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * A payroll line in one of the three shapes design section 9 allows.
 *
 * EVERY STATE HERE PRODUCES A ROW THAT SATISFIES
 * `payroll_lines_exactly_one_shape`
 * ----------------------------------------------
 * The constraint is an OR of three branches, each stating both what its shape
 * carries and what it must leave null, so a state that switches shape has to null
 * the previous one's columns as it goes. A state that set only its own columns
 * would satisfy no branch at all and every fixture built on it would fail on a
 * constraint rather than on its own assertion.
 *
 * The default is a **draft salary line**: `posting_period_start` and
 * `finalized_at` are both null, which is the state design section 14 requires to
 * be provable and the state a draft spends its whole reviewable life in.
 *
 * @extends Factory<PayrollLine>
 */
class PayrollLineFactory extends Factory
{
    protected $model = PayrollLine::class;

    /**
     * The monthly rate a default salary line freezes.
     *
     * One constant for both the `staff_compensation` row this factory creates and
     * the `frozen_rate` copied onto the line, because a frozen copy that differs
     * from what it was copied from describes nothing. It matches
     * StaffCompensationFactory's own default for the same reason.
     */
    private const SALARY_RATE = '2500.000';

    /** The per-hour rate an instructor line freezes. Mirrors StaffCompensationFactory::hourly(). */
    private const HOURLY_RATE = '35.000';

    /**
     * The hours an instructor line freezes.
     *
     * Written to `batch_instructor.assigned_hours` **and** to `frozen_hours`,
     * because the line is meant to be the frozen copy of that assignment. Design
     * section 14 tests what happens when the two diverge; a fixture that shipped
     * them already diverged would make that test prove nothing.
     */
    private const ASSIGNED_HOURS = 20;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $segment = CentreCalendar::localise(now())->subMonth()->startOfMonth();
        $daysInMonth = $segment->daysInMonth;

        return [
            'payroll_run_id' => PayrollRun::factory(),

            /*
             * FIRST, AND THE ORDER IS LOAD-BEARING. Laravel expands factory
             * attributes in array order, writing each resolved value back before
             * the next closure runs — so the closures below can read
             * `$attributes['user_id']` as a real id. The compensation row and the
             * instructor assignment both have to belong to the person the line
             * pays, or the fixture is a wage line explained by somebody else's
             * rate.
             */
            'user_id' => User::factory(),

            /*
             * The rate this line froze from, belonging to this line's own user.
             * A bare `StaffCompensation::factory()` would mint a second user and
             * leave a line whose stated explanation is another person's salary.
             */
            'staff_compensation_id' => fn (array $attributes): int => StaffCompensation::factory()
                ->create([
                    'user_id' => $attributes['user_id'],
                    'type' => CompensationType::Salary,
                    'amount' => self::SALARY_RATE,
                ])
                ->getKey(),

            // Null on a salary line, per the shape CHECK. See instructor().
            'batch_instructor_id' => null,

            // Null on anything that is not a correction. See adjustment().
            'corrects_payroll_line_id' => null,

            /*
             * ONE WHOLE CALENDAR MONTH. A segment never spans a month boundary —
             * that is the entire reason segments exist, because a salary
             * pro-rated across a boundary has two different denominators and one
             * row cannot express both.
             */
            'segment_start' => $segment->toDateString(),
            'segment_end' => $segment->endOfMonth()->toDateString(),

            'frozen_rate' => self::SALARY_RATE,

            // Null on a salary line — hours belong to the instructor shape.
            'frozen_hours' => null,

            /*
             * The pro-rating numerator and denominator. A whole month, so they
             * are equal and the line is worth exactly the monthly rate. The
             * denominator is frozen beside the numerator so a February segment
             * stays divided by 28 or 29 forever without a report re-deriving
             * which.
             */
            'frozen_days' => $daysInMonth,
            'frozen_days_in_month' => $daysInMonth,

            /*
             * `computed_amount` IS DELIBERATELY ABSENT — derived in configure()
             * from whichever shape and figures survive every state, using the
             * same `Money` arithmetic design section 7 specifies.
             */

            /*
             * A DRAFT: null posting period and null finalization. The CHECK is
             * one-directional — a finalized line must carry a posting period, a
             * draft need not — so this is the branch that proves a draft persists
             * without one.
             */
            'posting_period_start' => null,
            'finalized_at' => null,

            // Mandatory on a correction and unconstrained elsewhere.
            'reason' => null,
        ];
    }

    /**
     * Derive the two columns that must follow from the rest of the row.
     *
     * afterMaking, not definition(), because it runs after every state has been
     * merged and after factory attributes have been expanded to real ids — so it
     * sees the final shape rather than the default one, and can follow
     * `corrects_payroll_line_id` to the row it points at.
     *
     * Both derivations only fire when the column was not supplied, so a test can
     * always state an amount or a period explicitly and be left alone.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (PayrollLine $line): void {
            if ($line->computed_amount === null) {
                $line->computed_amount = self::deriveAmount($line);
            }

            /*
             * `payroll_lines_posting_period_required_when_finalized`. Without
             * this, every ->finalized() fixture would die on a constraint instead
             * of on its own assertion.
             */
            if ($line->finalized_at !== null && $line->posting_period_start === null) {
                $line->posting_period_start = self::derivePostingPeriod($line);
            }
        });
    }

    /**
     * An instructor-hours line: one batch, paid as a lump.
     *
     * Nulls every salary column, because the shape CHECK's instructor branch
     * requires it. The assignment is created here rather than expected from the
     * caller — `batch_instructor_id` is a real foreign key, and the row it points
     * at is the evidence for the amount, which design section 14 tests by
     * changing `assigned_hours` underneath a finalized line.
     */
    public function instructor(): static
    {
        return $this->state(fn (): array => [
            'staff_compensation_id' => fn (array $attributes): int => StaffCompensation::factory()
                ->create([
                    'user_id' => $attributes['user_id'],
                    'type' => CompensationType::Hourly,
                    'amount' => self::HOURLY_RATE,
                ])
                ->getKey(),

            'batch_instructor_id' => fn (array $attributes): int => self::assignToNewBatch(
                (int) $attributes['user_id'],
            ),

            'frozen_rate' => self::HOURLY_RATE,
            'frozen_hours' => self::ASSIGNED_HOURS,

            'segment_start' => null,
            'segment_end' => null,
            'frozen_days' => null,
            'frozen_days_in_month' => null,
        ]);
    }

    /**
     * A correction to a line in a run that is already finalized.
     *
     * Nulls **every** frozen column including `frozen_rate`, and
     * `staff_compensation_id` with them: a correction is a signed amount, not a
     * calculation, and forcing a rate onto it would store a meaningless number in
     * a column whose whole purpose is to explain how an amount was reached.
     *
     * `reason` is mandatory here — the correction's branch of the shape CHECK
     * requires it, because the reason is the only thing that explains the figure.
     *
     * The corrected line is created **finalized**, since design section 7 only
     * allows correcting a posted line. That also gives this row a real
     * `posting_period_start` to inherit, which is what makes a June correction
     * land in March's wage cost.
     *
     * @param  string  $amount  signed; a deduction is negative, and zero is refused
     *                          by the corresponding constraint on adjustments
     */
    public function adjustment(string $amount = '120.000'): static
    {
        return $this->state(fn (): array => [
            'corrects_payroll_line_id' => self::new()->finalized(),
            'reason' => fake()->sentence(),
            'computed_amount' => $amount,

            'staff_compensation_id' => null,
            'batch_instructor_id' => null,
            'segment_start' => null,
            'segment_end' => null,
            'frozen_rate' => null,
            'frozen_hours' => null,
            'frozen_days' => null,
            'frozen_days_in_month' => null,
        ]);
    }

    /**
     * Posted to the books, with the posting period the constraint demands.
     *
     * The period itself is derived in configure() rather than here, because at
     * state time `corrects_payroll_line_id` is still an unresolved factory and a
     * correction's period has to be read from the line it corrects.
     *
     * **`finalized_at` is denormalized onto the line rather than read from its
     * run** — the two generated columns that prevent paying twice need it on this
     * row, and a generated column may not reference another table. Finalizing the
     * run alone therefore does not finalize its lines; use both states together.
     */
    public function finalized(): static
    {
        return $this->state(fn (): array => ['finalized_at' => now()]);
    }

    /**
     * What this line is worth, by shape, using design section 7's arithmetic.
     *
     * `Money::multipliedBy()` is the single rounding implementation in the domain
     * — integer dirham, half-up — so a factory row is priced exactly the way
     * `FinalizePayrollRunAction` will price a real one. Doing this with `*` and
     * `round()` on the cast strings would put a second, float-based rounding
     * convention in the fixtures, and design section 6 measured that convention
     * disagreeing with this one on 1,687 of 802,800 cases.
     */
    private static function deriveAmount(PayrollLine $line): string
    {
        $rate = $line->frozen_rate === null
            ? null
            : Money::fromDecimal((string) $line->frozen_rate);

        if ($rate !== null && $line->frozen_days !== null && $line->frozen_days_in_month !== null) {
            return $rate->multipliedBy($line->frozen_days, $line->frozen_days_in_month)->toDecimal();
        }

        if ($rate !== null && $line->frozen_hours !== null) {
            return $rate->multipliedBy($line->frozen_hours, 1)->toDecimal();
        }

        /*
         * A correction, whose amount is a decision rather than a calculation.
         * adjustment() always supplies one, so reaching here means a caller built
         * a shape by hand without saying what it is worth; zero is the honest
         * answer and the shape CHECK will refuse the row anyway if it is not one
         * of the three.
         */
        return Money::zero()->toDecimal();
    }

    /**
     * The month this line's cost belongs to.
     *
     * Salary: the segment's month. Correction: copied from the line being
     * corrected, which is the whole mechanism behind back-dated wage cost.
     * Instructor: the month the run is finalized in, which is unknowable while
     * the line is a draft and is exactly why the column is nullable.
     */
    private static function derivePostingPeriod(PayrollLine $line): string
    {
        if ($line->segment_start !== null) {
            return CarbonImmutable::instance($line->segment_start)->startOfMonth()->toDateString();
        }

        if ($line->corrects_payroll_line_id !== null) {
            $corrected = PayrollLine::query()->find($line->corrects_payroll_line_id);

            if ($corrected?->posting_period_start !== null) {
                return CarbonImmutable::instance($corrected->posting_period_start)->toDateString();
            }
        }

        return CentreCalendar::localise(now())->startOfMonth()->toDateString();
    }

    /**
     * A fresh batch with this user assigned to it, and the pivot row's id.
     *
     * Returns the id rather than the model because `batch_instructor` is a pivot
     * with no Eloquent model — see PayrollLine's docblock for why Finance does not
     * invent one. Read back through the query builder for the same reason:
     * `attach()` returns nothing, and the composite unique index on
     * `(batch_id, user_id)` makes this lookup exact.
     */
    private static function assignToNewBatch(int $userId): int
    {
        $batch = Batch::factory()->create();

        $batch->instructors()->attach($userId, ['assigned_hours' => self::ASSIGNED_HOURS]);

        return (int) DB::table('batch_instructor')
            ->where('batch_id', $batch->getKey())
            ->where('user_id', $userId)
            ->value('id');
    }
}
