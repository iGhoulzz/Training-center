<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

use App\Domain\Enrollment\Enums\CertificateStatus;

/**
 * The whole public verifier response, in the shape the page renders directly.
 * Design section 6.5.
 *
 * EXACTLY SIX FIELDS, AND THAT IS THE WHOLE POINT OF THIS CLASS EXISTING.
 * -------------------------------------------------------------------------
 * `VerifyCertificateController` builds one of these by naming six properties
 * off a `StudentCertificate` row, never by handing the row itself — or its
 * `toArray()` — to the view. A column added to `student_certificates` in a
 * later phase therefore cannot reach the public internet by default; reaching
 * it requires someone to deliberately add a seventh property here and a
 * deliberate line in the controller that fills it in.
 *
 * `reference_number`, `enrollment_id`, `issued_by`, `revoked_at`, `revoked_by`,
 * `revocation_reason` and `replaces_certificate_id` are ABSENT ON PURPOSE.
 * Design section 6.5 lists exactly six things the verifier may show: status,
 * printed student name, course name, completion date, issue date, and centre
 * confirmation. A revoked or replaced certificate states its status here and
 * nothing more — no reason, no successor reference — because those columns
 * never reach this class in the first place, for any status.
 *
 * DATES ARRIVE PRE-FORMATTED ON THE CENTRE'S CALENDAR, NOT AS Carbon INSTANCES.
 * -------------------------------------------------------------------------
 * `App\Support\CentreCalendar::localise()` is what the controller runs both
 * dates through before they reach here, matching the shape
 * `GenerateReceiptJob` and `MyEnrollments` already hand their views — a plain
 * string the template drops in, not a `DateTimeInterface` a template would
 * have to remember to localise itself.
 */
final readonly class CertificateVerificationView
{
    public function __construct(
        public CertificateStatus $status,
        public string $studentName,
        public string $courseName,
        public string $completedOn,
        public string $issuedAt,
        public string $centreName,
    ) {}
}
