<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'batch_id' => Batch::factory(),
            'enrolled_at' => now(),
            'status' => EnrollmentStatus::Active,
            'completed_at' => null,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => ['status' => EnrollmentStatus::Withdrawn]);
    }

    /**
     * A completed enrolment, which PHASE 1 CANNOT PRODUCE.
     *
     * The application has no completion path — spec line 71 puts completion
     * marking in phase 3 — so this state exists only so tests can construct the
     * row WithdrawEnrollmentAction has to refuse. It is not a hint that a
     * completion feature is missing.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
