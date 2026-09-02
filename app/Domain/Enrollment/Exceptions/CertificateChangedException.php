<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * The certificate the actor was looking at is no longer the current one.
 *
 * WHY THIS EXISTS, AND WHAT WENT WRONG WITHOUT IT
 * -----------------------------------------------
 * Replace and Revoke operate on "the enrolment's currently valid certificate",
 * resolved under the lock. The panel's row actions, however, are clicked on a
 * SPECIFIC row — and the two are not the same thing once anybody else has acted
 * in between.
 *
 * The concrete failure cross-review found: operator A opens the revoke modal on
 * certificate C1. Operator B replaces C1, so C1 becomes `replaced` and C2
 * becomes `valid`. A submits the still-open modal. Without this check the Action
 * resolves "the currently valid certificate", finds C2, and revokes it — with
 * A's reason attached, against a row A never saw, and C1 left untouched. The
 * activity log then records A revoking a certificate A never looked at.
 *
 * So the caller may pass the row it believes it is acting on, and the Action
 * refuses if the lock disagrees. Callers that genuinely mean "whatever is
 * current" simply pass nothing, which is what the direct Action tests do.
 */
final class CertificateChangedException extends RuntimeException
{
    public function __construct(
        public readonly int $enrollmentId,
        public readonly int $expectedCertificateId,
        public readonly ?int $currentCertificateId,
    ) {
        parent::__construct(__('certificates.certificate_changed'));
    }
}
