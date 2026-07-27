<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * The enrolment's batch changed between choosing the mutex and locking the row.
 *
 * Unreachable through the application in phase 1 — nothing moves an enrolment
 * between batches. It exists so the invariant is enforced rather than assumed;
 * see EnrollmentMutex::acquire().
 */
final class EnrollmentBatchChangedException extends RuntimeException
{
    public function __construct(
        public readonly int $enrollmentId,
        public readonly int $expectedBatchId,
        public readonly int $actualBatchId,
    ) {
        parent::__construct(__('enrollment.enrollment_batch_changed'));
    }
}
