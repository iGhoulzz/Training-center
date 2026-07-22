<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Exceptions\CourseInUseException;
use App\Domain\Enrollment\Models\Course;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a course, refusing while any batch still references it.
 *
 * Actor first and self-authorizing, like every other request-path Action here:
 * a policy only runs when something chooses to consult it, and putting the
 * check inside the Action makes the answer binding for every caller — the
 * table row, the edit page, a console command, a future API.
 *
 * The refusal is enforced by `batches.course_id` being restrictOnDelete, not by
 * the pre-check below. The pre-check exists only to produce a readable message
 * in the ordinary case; a batch can still be created between the check and the
 * delete, and the foreign key is what actually guarantees that a course's
 * enrolment history is never silently destroyed.
 */
final class DeleteCourseAction
{
    /**
     * MySQL: "Cannot delete or update a parent row: a foreign key constraint
     * fails". This specific code is the only database error that means "still
     * in use" — everything else is a real failure and must surface as one.
     */
    private const FOREIGN_KEY_RESTRICTED = 1451;

    /**
     * @throws CourseInUseException if any batch still references the course.
     */
    public function execute(User $actor, Course $course): void
    {
        Gate::forUser($actor)->authorize('delete', $course);

        if ($course->batches()->exists()) {
            throw new CourseInUseException((int) $course->getKey());
        }

        try {
            DB::transaction(fn () => $course->delete());
        } catch (QueryException $exception) {
            // Lost the race: a batch appeared between the check and the delete.
            //
            // Only 1451 is converted. Catching QueryException wholesale would
            // report a connection failure, a deadlock, or a disk-full error as
            // "this course is still in use" — a reassuring message about a
            // completely different problem, and the kind of mistranslation that
            // hides an outage.
            if ($this->isForeignKeyRestriction($exception)) {
                throw new CourseInUseException((int) $course->getKey());
            }

            throw $exception;
        }
    }

    private function isForeignKeyRestriction(QueryException $exception): bool
    {
        // errorInfo is [SQLSTATE, driver code, message]; the driver code is
        // what distinguishes a restriction from any other integrity violation.
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === self::FOREIGN_KEY_RESTRICTED;
    }
}
