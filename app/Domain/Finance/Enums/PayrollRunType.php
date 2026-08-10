<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * The three kinds of payroll run, each with a different relationship to time.
 *
 * The three values are pinned by `payroll_runs_period_matches_type`, which
 * enumerates them as literals rather than writing `type <> 'monthly_salary'`.
 * That is deliberate: a fourth type would satisfy neither branch of the
 * constraint and be refused, which forces its period rule to be decided rather
 * than defaulted into. **Adding a case here without also amending that
 * constraint produces a value the database will not store.**
 *
 * FINALIZED MEANS POSTED, NOT PAID
 * --------------------------------
 * All three run types go draft → finalized → immutable, and finalizing approves
 * a run and posts it to the books. It does not disburse money; disbursement is
 * out of scope for the entire project (design section 7).
 */
enum PayrollRunType: string
{
    /**
     * A period run over salaried staff.
     *
     * Its lines are **segments**, not periods: the intersection of the run's
     * period, one calendar month, and one compensation row's validity range. A
     * run may legitimately cover a partial previous month plus a full current
     * one, and a salary pro-rated across a month boundary has two different
     * denominators (design section 7).
     */
    case MonthlySalary = 'monthly_salary';

    /**
     * An on-demand run paying instructor-hour assignments as one lump each.
     *
     * It carries no period, and that is not an omission:
     * `batch_instructor.assigned_hours` is per **batch** while a period is per
     * calendar, so a 30-hour batch running January to March has no defined
     * January figure. There is no calendar slicing and no pay-on-start /
     * pay-on-completion setting.
     */
    case InstructorBatch = 'instructor_batch';

    /**
     * A correction to a line in a run that is already finalized.
     *
     * Its lines carry a signed amount, a mandatory reason and
     * `corrects_payroll_line_id`. Nothing about the original moves, and the
     * correction posts to **the corrected line's period**, not to its own — which
     * is what makes a June correction land in March's wage cost.
     */
    case Adjustment = 'adjustment';

    /**
     * Does a run of this type carry `period_start` and `period_end`?
     *
     * The PHP side of `payroll_runs_period_matches_type`, so the rule is stated
     * once rather than re-derived wherever a run is built — the same reasoning
     * that keeps `Batch::scopeOpen()` and `BatchStatus::isOpen()` in step.
     *
     * Written as a whitelist rather than as `!== Adjustment`, so a fourth case
     * fails closed: a new run type carries no period until somebody decides it
     * does, and the constraint refuses it either way.
     */
    public function hasPeriod(): bool
    {
        return $this === self::MonthlySalary;
    }

    /**
     * The translated label for display.
     *
     * Plural key, singular reserved for the field label — see
     * TenderMethod::label() for the reasoning. `lang/en/payroll.php` is task 7's
     * file; until it exists this renders as the raw case value.
     */
    public function label(): string
    {
        $label = __("payroll.run_types.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
