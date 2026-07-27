<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/** The batch still has enrolments or instructor allocations. */
final class BatchInUseException extends RuntimeException
{
    public function __construct(public readonly int $batchId)
    {
        parent::__construct(__('enrollment.batch_in_use'));
    }
}
