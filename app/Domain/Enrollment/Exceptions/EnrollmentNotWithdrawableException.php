<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * Only an active enrolment can be withdrawn.
 *
 * Withdrawing an already-withdrawn row is a no-op rather than this exception —
 * see WithdrawEnrollmentAction. This is raised for a COMPLETED row, where phase 3
 * may have issued a certificate against the completion.
 */
final class EnrollmentNotWithdrawableException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('enrollment.enrollment_not_withdrawable'));
    }
}
