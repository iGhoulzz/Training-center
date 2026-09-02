<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * Replace or revoke was attempted against an enrolment with no valid
 * certificate to act on.
 *
 * Covers both ways that can be true: none was ever issued, or the one that
 * was has already been replaced or revoked by an earlier transition. Neither
 * ReplaceStudentCertificateAction nor RevokeStudentCertificateAction needs to
 * tell the two apart — both mean the same thing to the actor: there is
 * nothing currently valid to act on.
 */
final class NoValidCertificateException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('certificates.no_valid_certificate'));
    }
}
