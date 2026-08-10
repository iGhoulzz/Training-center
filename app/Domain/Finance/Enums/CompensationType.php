<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * What a compensation row prices: a month, or an hour.
 *
 * `per_student` IS GONE, AND IS NOT AN OVERSIGHT
 * ----------------------------------------------
 * The original system design had three types. The centre does not pay per head,
 * and design section 15 records the removal explicitly so that nobody
 * reintroduces it as a missing case. **Do not add it back.**
 *
 * ONE PERSON MAY HOLD BOTH
 * ------------------------
 * A salaried administrator who also teaches carries a `salary` row and an
 * `hourly` row at the same time. That is why the no-overlap rule in design
 * section 7 is per person **per type**, and why `staff_compensation` is indexed
 * on `(user_id, type, effective_from)`.
 *
 * WHICH TYPE A PAYROLL RUN READS
 * ------------------------------
 * A `monthly_salary` run reads `salary` rows and pro-rates each into month-long
 * segments; an `instructor_batch` run reads `hourly` rows and multiplies by the
 * frozen assigned hours. Getting this wrong pays somebody a monthly salary as an
 * hourly rate, which is why the column has no default.
 */
enum CompensationType: string
{
    /** A monthly amount, pro-rated by day across a segment (design section 7). */
    case Salary = 'salary';

    /** A per-hour rate, multiplied by a batch's frozen assigned hours. */
    case Hourly = 'hourly';

    /**
     * The translated label for display.
     *
     * Plural key, singular reserved for the field label — see
     * TenderMethod::label() for the reasoning. `lang/en/payroll.php` is task 7's
     * file; until it exists this renders as the raw case value.
     */
    public function label(): string
    {
        $label = __("payroll.compensation_types.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
