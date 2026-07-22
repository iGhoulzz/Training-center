<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->numerify('09########'),
            'job_title' => fake()->jobTitle(),
            'hire_date' => fake()->dateTimeBetween('-3 years'),
            'employment_type' => fake()->randomElement(EmploymentType::cases()),
            'qualifications' => fake()->sentence(),

            // Null by default, because that is the common case and the one the
            // UI has to handle: no photo means the avatar falls back to
            // initials. A test that had to opt into the default would not be
            // exercising the path most rows take.
            'profile_photo_path' => null,
        ];
    }

    /** Staff who teach — the ones P1-T10 may assign to a batch. */
    public function instructor(): static
    {
        return $this->state(fn (): array => [
            'employment_type' => EmploymentType::Instructor,
        ]);
    }

    /** Staff who work shifts at the centre rather than teaching. */
    public function administrative(): static
    {
        return $this->state(fn (): array => [
            'employment_type' => EmploymentType::Administrative,
        ]);
    }

    /**
     * A profile whose photo has been uploaded.
     *
     * The path points at the private disk; no file is written, because the
     * column stores a pointer and nothing in the model touches the filesystem.
     */
    public function withPhoto(): static
    {
        return $this->state(fn (): array => [
            'profile_photo_path' => 'staff-photos/'.fake()->uuid().'.jpg',
        ]);
    }
}
