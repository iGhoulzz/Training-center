<?php

declare(strict_types=1);

namespace App\Domain\Staff\Console;

use App\Domain\Staff\Exceptions\BackupDestinationUnavailableException;
use App\Domain\Staff\Support\BackupConfiguration;
use App\Domain\Staff\Support\SafeReporting;
use RuntimeException;

/**
 * Refuse to run when the backup destination is not there, and raise the alert.
 *
 * WHY THIS LIVES ON THE COMMANDS (P1-T17, review round 2)
 * ------------------------------------------------------
 * The check was first wired as `->before()` callbacks on the three schedules in
 * routes/console.php. Two things were wrong with that, and both were reported:
 *
 * 1. IT ONLY GUARDED THE SCHEDULER. `php artisan backup:run` typed by hand ran
 *    unchecked — including the drill in docs/RESTORE.md, which tells the
 *    operator to unmount the drive, run that exact command, and expect it to
 *    fail. It would have succeeded, writing the archive into the empty mount
 *    point on the server, where it dies with the machine.
 *
 * 2. IT SUPPRESSED THE ALERT. A `->before()` that throws means the command never
 *    starts, so Spatie never reaches the catch that dispatches BackupHasFailed.
 *    The scheduler reports the exception through the exception handler — a log
 *    line, not the configured backup-alert email. The run failed silently, in
 *    precisely the setup where somebody needs to be told.
 *
 * CommandStarting was tried next and rejected: Laravel does not dispatch it when
 * `runningUnitTests()` is true (Foundation\Console\Kernel::__construct), so the
 * guard could not have been exercised by a single test. A guard the suite cannot
 * fail is the failure mode this project keeps paying for.
 *
 * Overriding the commands themselves puts the check on the one path every
 * caller shares — scheduled, hand-typed, queued or Artisan::call() — while still
 * returning a real FAILURE exit code and printing a real error.
 */
trait RefusesAnUnavailableDestination
{
    /**
     * The failure exit code if the destination is unusable, or null to proceed.
     *
     * Returns rather than throws so the command exits the way every other
     * backup failure does: a non-zero status the scheduler and the operator's
     * shell both already understand.
     */
    protected function refuseIfDestinationIsUnavailable(): ?int
    {
        try {
            BackupConfiguration::assertDestinationReady();

            return null;
        } catch (RuntimeException $exception) {
            $unavailable = new BackupDestinationUnavailableException(
                $exception->getMessage(),
                previous: $exception,
            );

            $this->error($unavailable->getMessage());

            // What THIS command did about it. The exception only describes the
            // destination, because backup:list shares that text and carries on.
            $this->error('This command stopped without touching the destination.');

            /*
             * NOTIFY FIRST, AND REPORT IN A WAY THAT CANNOT INTERRUPT IT.
             *
             * A bare report() sat here, ahead of the notification. Laravel's
             * handler may throw when its logging transport is unavailable — and
             * a full disk breaks the log channel and the backup destination
             * together, so the correlated case is the likely one. The backup
             * would have failed with nothing sent to BACKUP_ALERT_EMAIL: the
             * precise alerting defect this guard exists to prevent.
             *
             * The order is now belt and braces. SafeReporting::report() cannot
             * throw, so nothing depends on the sequence; the alert still goes
             * first, so a later edit reintroducing a bare report() costs a log
             * line rather than the notification.
             *
             * --disable-notifications is honoured because Spatie honours it
             * further down in handle(), which this pre-empts. Read off the raw
             * input rather than through option(): backup:monitor does not define
             * the flag, and option() throws on an unknown name.
             */
            if (! $this->input->hasParameterOption('--disable-notifications')) {
                event($this->destinationUnavailableEvent($unavailable));
            }

            SafeReporting::report($unavailable);

            return static::FAILURE;
        }
    }

    /**
     * The notification event this command raises when it cannot reach storage.
     *
     * Each command has its own configured notification and an operator filters
     * on them, so refusing early must not collapse the three into one.
     */
    abstract protected function destinationUnavailableEvent(
        BackupDestinationUnavailableException $exception,
    ): object;
}
