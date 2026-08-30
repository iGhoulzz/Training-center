<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * Where one physical certificate stands in the register (design section 6.4).
 *
 * ONE ROW PER PIECE OF PAPER, NEVER PER ENROLMENT
 * ------------------------------------------------
 * A `StudentCertificate` row is never updated into a different document — it is
 * either the certificate currently in the student's hand, or a record of one
 * that used to be. `IssueStudentCertificateAction` (T5) inserts a `valid` row;
 * `ReplaceStudentCertificateAction` marks the old row `replaced` and inserts a
 * new `valid` one; `RevokeStudentCertificateAction` marks a `valid` row
 * `revoked`. No Action ever deletes a row or moves one back to `valid` — the
 * three values below are the only states this column will ever hold, which is
 * exactly what `chk_student_certificates_status` pins at the database.
 *
 *   - valid:    the certificate currently stands. At most one per enrolment,
 *               held by `uniq_valid_certificate_per_enrollment` on the
 *               `valid_enrollment_id` generated column.
 *   - replaced: superseded by a later `valid` row for the same enrolment. Kept
 *               rather than deleted, because a reprint has a history too.
 *   - revoked:  withdrawn by a human decision, with a mandatory actor, time and
 *               reason — `chk_student_certificates_revocation` requires all
 *               three together and refuses them on every other status.
 *
 * WHY THIS IS NOT A FINANCE-DOMAIN VIOLATION
 * -------------------------------------------
 * Phase 2's rule is that no Finance table carries a status string; lifecycle is
 * nullable fact columns and the label is derived. `student_certificates` lives
 * in Enrollment, and this column records a decision a human made — somebody
 * revoked this — not a value derivable from other rows. Design section 6.3
 * settles this explicitly rather than leaving it to be re-litigated per review.
 */
enum CertificateStatus: string
{
    case Valid = 'valid';
    case Revoked = 'revoked';
    case Replaced = 'replaced';

    /**
     * The translated label for display.
     *
     * `lang/en/certificates.php` is T5's file — the phase 3 plan puts it in that
     * task's scope, and T4 ships no screen that renders a label. Until it
     * exists this returns the KEY, `certificates.certificate_status.valid`,
     * because that is what Laravel's translator does with a missing key. Not the
     * case value: an earlier version of this docblock claimed otherwise and
     * wrapped the call in an is_string() fallback that could never fire.
     *
     * A visible key is the correct interim behaviour and the same shape every
     * other enum here uses. What matters from commit one is that no user-facing
     * string is hardcoded, not that the catalogue precedes the screen.
     */
    public function label(): string
    {
        return __("certificates.certificate_status.{$this->value}");
    }
}
