<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * This student is already on this batch.
 *
 * The message goes through __() because it reaches the panel as a notification.
 * See BatchClosedException for the full reasoning.
 */
final class DuplicateEnrollmentException extends RuntimeException
{
    public function __construct(
        public readonly int $studentId,
        public readonly int $batchId,
    ) {
        parent::__construct(__('enrollment.duplicate_enrollment'));
    }
}
