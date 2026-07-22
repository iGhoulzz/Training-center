<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'code' => 'BTC-'.Str::upper(Str::random(8)),
            'start_date' => now()->addWeek(),
            'end_date' => now()->addMonths(3),
            'capacity' => 20,

            /*
             * Null by default, and deliberately so: inheriting from the course
             * is the normal case, not the exception, and a fixture that always
             * overrode would never exercise the fallback these tests exist to
             * protect. Pass an explicit value to test the override.
             */
            'total_hours' => null,

            /*
             * Null, but NOT "inherit" — nothing reads price in phase 1. It
             * is a phase 2 column that phase 1 neither displays nor uses, so
             * there is deliberately no state here named after pricing
             * behaviour. Tests that care pass their own value.
             */
            'price' => null,

            'status' => BatchStatus::Planned,
        ];
    }

    /** Currently running. */
    public function active(): static
    {
        return $this->state(fn (): array => ['status' => BatchStatus::Active]);
    }

    /** Finished. Rejects new enrolments and instructor changes. */
    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => BatchStatus::Completed]);
    }

    /** Called off. Kept rather than deleted; the enrolments are still a fact. */
    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => BatchStatus::Cancelled]);
    }
}
