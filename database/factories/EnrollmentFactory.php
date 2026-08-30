<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Support\Reference;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
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

            /*
             * NOT THE REFERENCE THIS ROW ENDS UP WITH. `ENR-2026-000042`
             * contains the row's own id, which does not exist until the insert
             * has run — and `enrollments.reference` is NOT NULL UNIQUE, so the
             * insert cannot omit it either.
             *
             * So the factory does exactly what EnrollStudentAction does: insert
             * a unique placeholder, then replace it with the real reference in
             * configure() below. Anything else would mean fixtures and the
             * production path producing differently-shaped rows, which is how a
             * test ends up proving something about a row the application cannot
             * actually create.
             *
             * Str::freezeUuids() in a test makes every placeholder identical and
             * two enrolments then collide on enrollments_reference_unique. That
             * is the unique constraint working, exactly as Reference::placeholder()
             * documents — not something for this factory to work around.
             */
            'reference' => Reference::placeholder(),
        ];
    }

    /**
     * Replace the placeholder with the real reference, once the row has an id.
     *
     * WHY afterCreating AND NOT A SEQUENCE
     * ------------------------------------
     * A `Sequence` is evaluated while the attributes are being built, so it
     * cannot see the id — it would have to invent its own counter, and a second
     * counter is a second definition of what an `ENR-` number is. This callback
     * runs after the insert, so it reads the id the database actually assigned.
     *
     * WHY IT CANNOT COLLIDE
     * ---------------------
     * `ENR-{year}-{id}` is a function of the primary key, and the primary key is
     * unique by construction. Two rows created in the same year get different
     * ids and therefore different references; two rows created in different
     * years cannot share an id either. `count(50)->create()` is fine, and so are
     * concurrent creates — the value is minted from an id the database already
     * committed to this row rather than from a counter two writers could read at
     * the same time.
     *
     * saveQuietly(), NOT update(). EnrollStudentAction fires the `updated` event
     * here because it is inside the audited write path and `reference` is
     * excluded from Enrollment::auditedAttributes() precisely so that event logs
     * nothing. A fixture has no such obligation, and an `updated` event on every
     * factory-built enrolment is a model event that any future observer would
     * have to learn to ignore. The row is identical either way.
     *
     * make() leaves the placeholder in place, which is correct: an unsaved model
     * has no id to build a reference from.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Enrollment $enrollment): void {
            $enrollment->reference = Reference::format(
                Reference::ENROLLMENT_PREFIX,
                /*
                 * The centre's calendar, not UTC. `enrolled_at` is what dates an
                 * enrolment document, and CentreCalendar owns which calendar it
                 * is read on — shared with EnrollStudentAction and the backfill
                 * migration so all three mint the same reference for the same
                 * row (design section 8).
                 */
                CentreCalendar::yearOf($enrollment->enrolled_at),
                (int) $enrollment->getKey(),
            );

            $enrollment->saveQuietly();
        });
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => ['status' => EnrollmentStatus::Withdrawn]);
    }

    /**
     * A completed enrolment, written directly rather than through the Action.
     *
     * This state predates the feature: it existed so phase 1 tests could
     * construct the row WithdrawEnrollmentAction has to refuse, back when
     * nothing could produce one. P3-T03 added the real path.
     *
     * It is still the right tool for a FIXTURE — a test that needs a completed
     * enrolment to exist, not one that exercises completing it. A test of the
     * transition itself must call CompleteEnrollmentAction, or it proves only
     * that the factory can set a column.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
