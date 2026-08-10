<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /**
     * Percentages that satisfy `discounts_percentage_within_range`.
     *
     * Held as **strings**, not floats. `Money::afterDiscount()` reads a
     * percentage as integer hundredths of a percent and refuses anything it
     * cannot hold exactly, so a fixture that generated `10.000000000000002`
     * would fail arithmetic that is correct. 33.33 is in the list on purpose —
     * it is the case a two-place column exists for.
     *
     * @var list<string>
     */
    private const PERCENTAGES = ['5.00', '10.00', '15.00', '25.00', '33.33', '50.00', '100.00'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * `discounts.name` is UNIQUE — two "Staff family" rows would make the
             * operator's choice at the desk a guess. A random suffix rather than
             * fake()->unique()->words(), whose pool is finite and throws once a
             * suite exhausts it. BatchFactory generates `code` the same way.
             */
            'name' => 'Discount '.Str::upper(Str::random(8)),

            'percentage' => fake()->randomElement(self::PERCENTAGES),

            /*
             * Active, because that is the state a definition spends almost all of
             * its life in and the only one the enrolment selector offers. A
             * fixture that had to opt in to the normal case would leave the
             * normal case untested.
             */
            'is_active' => true,
        ];
    }

    /**
     * Retired from the selector, and changing nothing about issued bills.
     *
     * The one lifecycle transition this table has, owned by
     * `DeactivateDiscountAction`. Every charge that already used this definition
     * froze the percentage at issue, so deactivating moves none of them.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** A fully waived enrolment — 100%, which produces a 0.000 charge, not no charge. */
    public function fullWaiver(): static
    {
        return $this->state(fn (): array => ['percentage' => '100.00']);
    }
}
