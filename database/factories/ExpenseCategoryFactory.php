<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A random suffix rather than fake()->unique(), whose pool is finite
            // and throws once a suite exhausts it. Two categories sharing a name
            // is refused by the Actions, so a fixture should not produce one.
            'name_en' => 'Category '.Str::upper(Str::random(8)),

            // Null by default: Arabic arrives with the phase 4 catalogues, so an
            // untranslated category is the normal state and the one name() must
            // fall back for.
            'name_ar' => null,

            // Active, because that is the state a category spends nearly all of
            // its life in and the only one the expense form offers.
            'is_active' => true,
        ];
    }

    /** Retired from the picker, and changing nothing about expenses already recorded. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
