<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * An issued certificate makes its enrolment part of the permanent register.
 */
final class EnrollmentHasCertificateException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('enrollment.enrollment_has_certificate'));
    }
}
