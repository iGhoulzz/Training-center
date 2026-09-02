<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * Every bounded attempt to mint a unique certificate reference collided.
 *
 * THE OUTCOME THE BOUNDED RETRY ALWAYS IMPLIED AND NEVER HAD. An earlier version
 * of both Actions ended their retry loop with a generic, hard-coded
 * `RuntimeException` carrying a developer diagnostic. Nothing caught it, so a
 * generator fault or a pathological collision run reached the operator as an
 * untranslated 500 — while `docs/ENGINEERING.md` requires typed domain
 * exceptions and a readable refusal, and the plan requires that no raw driver
 * error reaches a user.
 *
 * WHAT IT ACTUALLY MEANS. Five consecutive draws from a 31^8 alphabet colliding
 * is not bad luck; it is a broken or non-random picker. The message says
 * "try again" because a transient fault is the only case the operator can do
 * anything about, and the attempt count is carried for the log rather than the
 * screen.
 */
final class CertificateReferenceExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly int $enrollmentId,
        public readonly int $attempts,
    ) {
        parent::__construct(__('certificates.reference_exhausted'));
    }
}
