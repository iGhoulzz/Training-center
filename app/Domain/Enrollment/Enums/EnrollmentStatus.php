<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * Where a student stands on one batch.
 *
 * COMPLETED IS UNREACHABLE IN PHASE 1, AND THAT IS DELIBERATE.
 * ------------------------------------------------------------
 * Spec line 71 assigns completion marking to phase 3, alongside the student
 * portal and certificate issuance. Phase 1 ships no path to this case: there is
 * no CompleteEnrollmentAction and no status field in any form. The case is
 * declared now because the column's value set is fixed by the spec (line 231)
 * and because WithdrawEnrollmentAction has to refuse a completed row.
 *
 * Do not add a completion path here to "finish" the enum. Phase 3 owns it, and
 * it arrives with the certificate rules that depend on it.
 */
enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';

    /** The translated label for display. Task 14 supplies lang/. */
    public function label(): string
    {
        return __("enrollment.enrollment_status.{$this->value}");
    }
}
