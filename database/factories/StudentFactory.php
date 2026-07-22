<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Null by default, and deliberately so: most students never receive
            // a portal login, and a fixture that handed every student an
            // account would exercise the rare path as if it were the normal
            // one. withAccount() opts in.
            'user_id' => null,

            'student_code' => 'STU-'.Str::upper(Str::random(8)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09########'),
            'national_id' => fake()->numerify('############'),
            'date_of_birth' => fake()->dateTimeBetween('-40 years', '-16 years'),
            'gender' => fake()->randomElement(['male', 'female']),
            'address' => fake()->address(),
            'status' => StudentStatus::Active,
            'notes' => null,
        ];
    }

    /** Enquired but not yet enrolled — the state a new record starts in. */
    public function prospective(): static
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Prospective]);
    }

    /** Finished their studies at the centre. */
    public function graduated(): static
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Graduated]);
    }

    /** On the books but not studying — lapsed, withdrawn, or paused. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Inactive]);
    }

    /**
     * A student who also has a portal login (the phase 3 case).
     *
     * Building the user here rather than requiring one to be passed keeps the
     * FK-behaviour tests honest: they need a real users row to delete.
     */
    public function withAccount(): static
    {
        return $this->state(fn (): array => ['user_id' => User::factory()]);
    }
}
