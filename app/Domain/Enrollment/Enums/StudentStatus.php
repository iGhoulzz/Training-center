<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * Where a student stands with the centre.
 *
 * The four values are fixed by the spec (section 6, "Status enums") so they are
 * not invented inconsistently as later tasks touch them:
 *
 *   - prospective: enquired, not yet enrolled. The default for a new record.
 *   - active:      currently enrolled on at least one batch.
 *   - graduated:   completed their studies.
 *   - inactive:    on the books but not studying — lapsed, withdrawn, paused.
 *
 * This is a description of the person's relationship with the centre, not a
 * derived roll-up of their enrolments. P1-T11 adds enrolments with a status of
 * their own; neither column computes the other.
 */
enum StudentStatus: string
{
    case Prospective = 'prospective';
    case Active = 'active';
    case Graduated = 'graduated';
    case Inactive = 'inactive';

    /**
     * The translated label for display.
     *
     * The lang/ files arrive in P1-T14, so until then these keys render as
     * themselves. That is expected: what matters from commit one is that no
     * user-facing string is hardcoded here.
     */
    public function label(): string
    {
        $label = __("enrollment.student_status.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
