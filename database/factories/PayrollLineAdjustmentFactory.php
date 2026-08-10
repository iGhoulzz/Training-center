<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollLineAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollLineAdjustment>
 */
class PayrollLineAdjustmentFactory extends Factory
{
    protected $model = PayrollLineAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * A DRAFT line, which is the only kind that can take one of these.
             * PayrollLineFactory's default is a draft salary line, so this is the
             * default rather than something to opt into — an adjustment against a
             * finalized line is not a state the application can reach, and a
             * fixture that produced one would let a test assert against an
             * impossible row.
             */
            'payroll_line_id' => PayrollLine::factory(),

            /*
             * A bonus. `CHECK (amount <> 0)` — not a positivity check, because
             * negative is not merely allowed here, it is half of what the column
             * is for. Zero is refused: an adjustment of nothing is a reason with
             * no effect that would still appear in the audit trail as though
             * something had happened.
             *
             * A decimal STRING, never a float.
             */
            'amount' => '150.000',

            /*
             * NOT NULL in the schema. An adjustment with no explanation is a
             * figure nobody can defend at the end of the month, and the mandatory
             * reason is the only thing separating this from an unexplained change
             * to somebody's wage.
             */
            'reason' => fake()->sentence(),

            'created_by' => User::factory(),
        ];
    }

    /**
     * A deduction — unpaid leave, an advance being recovered.
     *
     * The same operation as a bonus with a different sign, which is why the
     * column is `decimal(12, 3)` without `unsigned()`.
     */
    public function deduction(): static
    {
        return $this->state(fn (): array => ['amount' => '-150.000']);
    }
}
