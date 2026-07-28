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
     * employment_typeS, plural, since P1-T14 supplied the catalogue: the
     * SINGULAR is the field label, "Employment type". One key cannot be both a
     * string and an array — __('staff.employment_type') would hand Filament the
     * case list and fatal on it. lang/en/enrollment.php already splits them the
     * same way: 'status' labels the column, 'batch_status' holds the cases.
     */
    public function label(): string
    {
        $label = __("staff.employment_types.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
