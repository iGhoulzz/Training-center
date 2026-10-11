<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(),

            // A string, never a float: `decimal:3` hands PHP a string and a float
            // cannot hold these values exactly. Strictly positive, per
            // `expenses_amount_positive`.
            'amount' => '125.500',

            // The centre's own calendar date, not the UTC one — the column is a
            // date the money left, and a fixture built from UTC "now" would be
            // yesterday for two hours a day.
            'paid_on' => CentreCalendar::localise(now())->toDateString(),

            'description' => fake()->sentence(4),

            'recorded_by' => User::factory(),

            // All three reversal columns null together, per
            // `expenses_reversal_columns_paired`. See reversed().
            'reversed_at' => null,
            'reversed_by' => null,
            'reversal_reason' => null,
        ];
    }

    /**
     * An expense that has been undone.
     *
     * Set-once on an otherwise immutable row, and all three columns move together
     * because `expenses_reversal_columns_paired` refuses a reversal with no actor
     * or no reason. Nothing is deleted: `reversed_at` is the only thing that takes
     * the expense out of every profit figure.
     */
    public function reversed(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'reversed_at' => now(),
            'reversed_by' => $actor?->getKey() ?? User::factory(),
            'reversal_reason' => fake()->sentence(),
        ]);
    }
}
