<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use Throwable;

/**
 * Report an exception without letting the report become the failure.
 *
 * WHY THIS EXISTS AS ONE THING (P1-T17, review round 3)
 * ----------------------------------------------------
 * Laravel's exception handler is allowed to throw — most obviously when its
 * logging transport is unavailable. That matters more than it sounds, because
 * the conditions that break logging are the same ones that break the thing
 * being reported: A FULL DISK TAKES DOWN BOTH THE STORAGE A FEATURE RECONCILES
 * AND THE LOG CHANNEL IT REPORTS TO. The two failures are correlated, so the
 * unlucky path is the likely one.
 *
 * This project has now been bitten three times in the same shape:
 *
 * - FileLifecycleService — a bare report() could replace the storage exception.
 * - SweepPendingFileDeletionsCommand — a throwing report() aborted the sweep
 *   loop on its first failed receipt, re-creating the starvation the sweep had
 *   just been fixed to prevent.
 * - RefusesAnUnavailableDestination — a throwing report() ran BEFORE the
 *   backup-failure notification was dispatched, so the backup failed and
 *   BACKUP_ALERT_EMAIL received nothing. That is the exact alerting defect the
 *   round it appeared in existed to close.
 *
 * The first two carried a comment saying a third caller was the point at which
 * to extract a shared helper. This is that third caller.
 *
 * THE RULE THIS ENCODES: when reporting sits between a failure and the action
 * that responds to it — an alert, a compensation, the next item in a loop —
 * report() must not be able to interrupt it.
 */
final class SafeReporting
{
    /**
     * Hand the exception to the handler, and swallow anything the handler throws.
     *
     * Nothing safer exists to do with a reporting failure: by definition, the
     * channel that would record it is the one that just failed. The caller's own
     * failure signal — an exit code, a notification, a durable receipt — is what
     * carries the information onward, which is precisely why this must not
     * interrupt it.
     */
    public static function report(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Deliberately empty. See the docblock: there is no second channel.
        }
    }
}
