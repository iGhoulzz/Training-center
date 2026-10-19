<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * The student still matters to the centre's books and cannot be deleted
 * (P4-T33, QA Q2: "refuse while owing").
 *
 * One type, two reasons, each with the rule's own sentence as the message, so
 * the delete buttons can show it as it stands.
 */
final class StudentNotDeletableException extends RuntimeException
{
    private function __construct(public readonly int $studentId, string $message)
    {
        parent::__construct($message);
    }

    public static function hasActiveEnrollment(int $studentId): self
    {
        return new self($studentId, __('enrollment.student_delete_has_active_enrollment'));
    }

    public static function owesBalance(int $studentId): self
    {
        return new self($studentId, __('enrollment.student_delete_owes_balance'));
    }
}
