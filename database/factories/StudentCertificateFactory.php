<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCertificate>
 */
class StudentCertificateFactory extends Factory
{
    protected $model = StudentCertificate::class;

    /**
     * A valid certificate, which is the only state issuance produces.
     *
     * THE STATES BELOW ARE NOT INTERCHANGEABLE WITH THE DEFAULT. `replaced()`
     * and `revoked()` describe rows that already went through a transition, and
     * the database enforces the difference: `chk_student_certificates_revocation`
     * demands actor, time and a non-blank reason on a revoked row and refuses
     * all three on any other status, so `->revoked()` cannot be approximated by
     * setting the status alone.
     *
     * THE REFERENCE COMES FROM THE REAL GENERATOR. Handing the factory its own
     * format string would let a test pass against a reference shape production
     * never mints — and T8's verifier route is built around that exact shape.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'reference_number' => app(CertificateReference::class)->mint(),

            /*
             * The issuance snapshot, deliberately NOT derived from the enrolment
             * this factory just built.
             *
             * A factory that read through to the student and course would make
             * every test agree with itself: the whole point of these columns is
             * that they are frozen copies which do NOT track the source rows, so
             * a test proving that needs the two to be independently settable.
             * T5's Action is what copies the real values across at issuance.
             */
            'student_name' => $this->faker->name(),
            'course_name' => $this->faker->words(3, true),
            'completed_on' => now()->subWeek()->toDateString(),

            'issued_at' => now(),
            'issued_by' => User::factory(),
            'status' => CertificateStatus::Valid,
            'replaces_certificate_id' => null,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
        ];
    }

    /**
     * A certificate that has been superseded by a later one.
     *
     * Carries no revocation fields: replacement and revocation are different
     * endings, and the CHECK constraint refuses a `replaced` row that carries
     * revocation metadata.
     */
    public function replaced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CertificateStatus::Replaced,
        ]);
    }

    /**
     * A revoked certificate, with the three fields the database requires.
     *
     * The reason is a real sentence rather than a single character: the
     * constraint requires at least one non-whitespace character, and a fixture
     * that only just clears a constraint teaches the next reader the wrong
     * minimum.
     */
    public function revoked(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CertificateStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => User::factory(),
            'revocation_reason' => $reason ?? 'Issued against a miscounted attendance record.',
        ]);
    }
}
