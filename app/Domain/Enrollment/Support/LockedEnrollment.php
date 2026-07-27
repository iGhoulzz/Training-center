<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Domain\Enrollment\Models\Enrollment;

/**
 * An enrolment and the batch mutex held over it, both locked, both revalidated.
 *
 * A named pair rather than a two-element array, so callers cannot silently swap
 * the elements and so the batch id carries its meaning: it is the id the mutex
 * was taken on, which the enrolment has been proven to belong to.
 */
final readonly class LockedEnrollment
{
    public function __construct(
        public int $batchId,
        public Enrollment $enrollment,
    ) {}
}
