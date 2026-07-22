<?php

declare(strict_types=1);

namespace App\Domain\Staff\Enums;

/**
 * The two kinds of staff the centre actually employs.
 *
 * Instructors teach courses; administrative and support staff work shifts at
 * the centre. The distinction is not cosmetic — it gates who may be assigned to
 * a batch (P1-T10), so it lives in the schema rather than in a job title string.
 */
enum EmploymentType: string
{
    case Instructor = 'instructor';
    case Administrative = 'administrative';
    case Support = 'support';

    /**
     * The translated label for display.
     *
     * The lang/ files arrive in P1-T14, so until then these keys render as
     * themselves. That is expected: what matters from commit one is that no
     * user-facing string is hardcoded here.
     */
    public function label(): string
    {
        $label = __("staff.employment_type.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
