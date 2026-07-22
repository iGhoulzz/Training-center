<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * Where a batch stands in its own lifecycle.
 *
 * The four values are fixed by the spec (section 6, "Status enums") so they are
 * not invented inconsistently as later tasks touch them:
 *
 *   - planned:   scheduled but not yet started. The default for a new batch.
 *   - active:    currently running.
 *   - completed: finished. Its enrolment history stays, and stays readable.
 *   - cancelled: called off. Kept rather than deleted, because whoever enrolled
 *                and whatever was arranged is still a fact about the centre.
 *
 * This describes the batch, not a roll-up of its enrolments. P1-T11 adds
 * enrolments with a status of their own; neither column computes the other.
 */
enum BatchStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * The translated label for display.
     *
     * The lang/ files arrive in P1-T14, so until then these keys render as
     * themselves. That is expected: what matters from commit one is that no
     * user-facing string is hardcoded here.
     */
    public function label(): string
    {
        $label = __("enrollment.batch_status.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }

    /**
     * Whether the batch is still taking part in the centre's live scheduling.
     *
     * Spec section 6: completed and cancelled batches reject new enrolments and
     * instructor changes. Stated positively, and as a whitelist rather than a
     * `!== Completed` test, so that adding a fifth status later fails closed —
     * a new value is not open until someone decides it is.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::Active], strict: true);
    }
}
