<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffCertificate>
 */
class StaffCertificateFactory extends Factory
{
    protected $model = StaffCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedOn = fake()->dateTimeBetween('-5 years', '-1 month');

        return [
            'staff_profile_id' => StaffProfile::factory(),
            'title' => fake()->words(3, asText: true),
            'issued_on' => $issuedOn,
            'expires_on' => fake()->dateTimeBetween('+6 months', '+3 years'),
            'original_filename' => fake()->slug(2).'.pdf',

            // The private disk from config/filesystems.php. Recorded per row so
            // a later move to another disk does not orphan these rows.
            'disk' => 'private',
            'path' => 'staff-certificates/'.fake()->uuid().'.pdf',
        ];
    }

    /** A lapsed accreditation — expired yesterday or earlier. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'issued_on' => fake()->dateTimeBetween('-8 years', '-4 years'),
            'expires_on' => fake()->dateTimeBetween('-2 years', '-1 day'),
        ]);
    }

    /**
     * A credential that does not expire, such as a degree.
     *
     * A null expires_on is the whole point of the column being nullable, so it
     * gets a named state rather than being spelled out at every call site.
     */
    public function neverExpires(): static
    {
        return $this->state(fn (): array => [
            'expires_on' => null,
        ]);
    }

    /** Expires today — the boundary case, which counts as still valid. */
    public function expiringToday(): static
    {
        return $this->state(fn (): array => [
            'expires_on' => today(),
        ]);
    }
}
