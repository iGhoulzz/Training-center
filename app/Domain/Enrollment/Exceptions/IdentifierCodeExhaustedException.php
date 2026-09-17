<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * Every bounded attempt to generate a unique student or batch code collided.
 *
 * CertificateReferenceExhaustedException's reasoning, unchanged: five
 * consecutive collisions over 31^6 per prefix and year is a broken picker, not
 * bad luck. The message says "try again" because a transient fault is the only
 * case the operator can act on; the column and attempt count are carried for
 * the log, not the screen.
 */
final class IdentifierCodeExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly string $column,
        public readonly int $attempts,
    ) {
        parent::__construct(__('enrollment.identifier_code_exhausted'));
    }
}
