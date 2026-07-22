<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * A course cannot be deleted while batches still reference it.
 *
 * Typed rather than a bare QueryException so callers can tell "this course is
 * still in use" apart from "the database is unreachable". Only MySQL error
 * 1451 — the foreign key restriction — is converted into this; every other
 * database failure is rethrown untouched, because swallowing them would turn a
 * genuine outage into a friendly message about batches.
 */
class CourseInUseException extends RuntimeException
{
    public function __construct(public readonly int $courseId)
    {
        parent::__construct(__('enrollment.course_in_use'));
    }
}
