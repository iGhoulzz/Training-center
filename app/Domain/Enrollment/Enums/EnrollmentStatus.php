<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * Where a student stands on one batch.
 *
 * COMPLETED WAS UNREACHABLE UNTIL PHASE 3, AND IS NOW REACHED BY EXACTLY ONE
 * PATH.
 * --------------------------------------------------------------------------
 * The case was declared in phase 1 because the column's value set is fixed by
 * the spec (line 231) and because WithdrawEnrollmentAction has to refuse a
 * completed row — but nothing could produce one, and this docblock said so.
 *
 * P3-T03 built the path the old text told readers to wait for:
 * CompleteEnrollmentAction moves Active → Completed under the batch → enrolment
 * lock, and ReverseEnrollmentCompletionAction moves it back while refusing to
 * do so beneath a valid certificate. Those two Actions are still the ONLY way
 * this case is written; no form offers a status field, and an enrolment payload
 * naming one is refused.
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
