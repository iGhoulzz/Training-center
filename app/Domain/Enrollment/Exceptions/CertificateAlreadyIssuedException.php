<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

/**
 * A valid certificate already stands for this enrolment.
 *
 * IssueStudentCertificateAction throws this from two distinct places, and both
 * are deliberate (design section 6.5):
 *
 *   - the ordinary path, where the locking existence check on
 *     `student_certificates` finds the row itself, under the enrolment lock;
 *   - the collision path, where the database's own
 *     uniq_valid_certificate_per_enrollment index catches a case the PHP-level
 *     check did not — see the Action's own docblock for why that index exists
 *     at all despite the lock.
 *
 * Both are the same refusal — "a certificate already stands" — and this
 * exception carries no flag distinguishing them, because a caller has no
 * different action to take either way. CertificateConcurrencyTest and
 * IssueCertificateTest exercise the two paths separately; neither needs the
 * exception itself to say which one fired.
 */
final class CertificateAlreadyIssuedException extends RuntimeException
{
    public function __construct(public readonly int $enrollmentId)
    {
        parent::__construct(__('certificates.certificate_already_issued'));
    }
}
