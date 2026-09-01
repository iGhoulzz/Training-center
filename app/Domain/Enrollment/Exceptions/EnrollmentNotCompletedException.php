<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * A certificate can only be issued against a completed enrolment (design
 * section 6.4).
 *
 * Covers every non-completed status with one message — an active enrolment
 * (never finished) and a withdrawn one (left before finishing) are both
 * refused the same way. Matches EnrollmentNotCompletableException's shape for
 * the mirror-image transition: the caller does not need to know which of the
 * two it was, only that issuance does not apply yet.
 */
final class EnrollmentNotCompletedException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('certificates.enrollment_not_completed'));
    }
}
