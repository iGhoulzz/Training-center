<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * Only an active enrolment can be completed.
 *
 * Covers BOTH non-active states with one message, matching
 * EnrollmentNotWithdrawableException's shape: a withdrawn student never
 * finished, and a second completion attempt on an already-completed row is
 * refused rather than treated as idempotent — unlike withdrawal, nothing here
 * makes a double click harmless, because silently re-stamping `completed_at`
 * would erase the original completion time a certificate may already have been
 * issued against.
 */
final class EnrollmentNotCompletableException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('enrollment.enrollment_not_completable'));
    }
}
