<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

use App\Domain\Enrollment\Enums\CertificateStatus;

/**
 * The whole public verifier response, in the shape the page renders directly.
 * Design section 7.3.
 *
 * A CLOSED, NAMED PROJECTION — THAT IS THE POINT, NOT THE FIELD COUNT.
 * -------------------------------------------------------------------------
 * `VerifyCertificateController` builds one of these by naming each property off
 * a `StudentCertificate` row, never by handing the row itself — or its
 * `toArray()` — to the view. A column added to `student_certificates` in a later
 * phase therefore cannot reach the public internet by default; reaching it
 * requires someone to deliberately add a property here AND a deliberate line in
 * the controller that fills it in.
 *
 * WHY THIS IS SEVEN FIELDS WHERE THE DESIGN SAYS SIX (cross-review of P3-T08).
 * -------------------------------------------------------------------------
 * Design §7.3 says both things and they cannot both hold: it calls this a
 * "six-field projection", and one paragraph later it requires a revoked
 * certificate to state "revoked on 4 March 2026". The revocation date is a
 * seventh value; six fields cannot carry it, and `issued_at` is a different date
 * that happens to coincide only by accident.
 *
 * The contradiction is resolved in favour of the BEHAVIOUR, because that is what
 * Task 8's Done-when also requires and what a person holding a bad certificate
 * actually needs to read. "Six" was a count of the then-known fields, not the
 * guarantee; the guarantee is that this list is closed, named and deliberate,
 * and that survives a seventh entry intact.
 *
 * Recorded for T14: design §7.3's "six-field" wording is now stale and wants
 * correcting to name the closure rather than the number. This task does not own
 * that file.
 *
 * `reference_number`, `enrollment_id`, `issued_by`, `revoked_by`,
 * `revocation_reason` and `replaces_certificate_id` are ABSENT ON PURPOSE. A
 * revoked certificate states that it was revoked and when — no reason, no
 * successor reference, no actor — because those columns never reach this class
 * in the first place, for any status.
 *
 * DATES ARRIVE PRE-FORMATTED ON THE CENTRE'S CALENDAR, NOT AS Carbon INSTANCES.
 * -------------------------------------------------------------------------
 * `App\Support\CentreCalendar::localise()` is what the controller runs every
 * date through before it reaches here, matching the shape `GenerateReceiptJob`
 * and `MyEnrollments` already hand their views — a plain string the template
 * drops in, not a `DateTimeInterface` a template would have to remember to
 * localise itself.
 */
final readonly class CertificateVerificationView
{
    /**
     * @param  string|null  $revokedOn  The centre-local revocation date, and null
     *                                  for every status but `revoked`. Null rather
     *                                  than an empty string so a template cannot
     *                                  render a blank date and look correct.
     */
    public function __construct(
        public CertificateStatus $status,
        public string $studentName,
        public string $courseName,
        public string $completedOn,
        public string $issuedAt,
        public string $centreName,
        public ?string $revokedOn = null,
    ) {}
}
