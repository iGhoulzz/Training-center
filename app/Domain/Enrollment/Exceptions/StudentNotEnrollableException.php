<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/** The student record is soft-deleted and cannot take new enrolments. */
final class StudentNotEnrollableException extends RuntimeException
{
    public function __construct(public readonly int $studentId)
    {
        parent::__construct(__('enrollment.student_not_enrollable'));
    }
}
