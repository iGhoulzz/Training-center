<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CRS-'.Str::upper(Str::random(6)),
            'name_en' => Str::title(fake()->words(3, true)),

            // Null by default, and deliberately so: the Arabic catalogue arrives
            // in phase 4, so an untranslated course is the normal state, not a
            // degenerate one. It is also the case Course::name() falls back for,
            // and a fixture that always translated would never exercise it.
            'name_ar' => null,

            'description_en' => fake()->sentence(),
            'description_ar' => null,
            'total_hours' => fake()->randomElement([20, 30, 40, 60]),

            /*
             * A plain constant, NOT a random price and NOT a state named after
             * pricing. default_price is a phase 2 column that phase 1 neither
             * displays nor uses; a fixture implying pricing behaviour would
             * invite phase 1 code to start depending on it. Tests that care
             * about the column pass their own value.
             */
            'default_price' => 0,

            'is_active' => true,
        ];
    }

    /** Retired from the catalogue, but still referenced by its past batches. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** A course with an Arabic name, for the localized-name path. */
    public function translated(): static
    {
        return $this->state(fn (): array => ['name_ar' => 'دورة اللغة الإنجليزية']);
    }
}
