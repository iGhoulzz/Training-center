<?php

declare(strict_types=1);

namespace App\Domain\Staff\Console;

use App\Domain\Staff\Exceptions\BackupDestinationUnavailableException;
use Spatie\Backup\Commands\CleanupCommand;
use Spatie\Backup\Events\CleanupHasFailed;

/**
 * `backup:clean`, refusing to apply retention to a destination it cannot see.
 *
 * The most dangerous of the three to let through: against an empty mount point
 * it evaluates retention over the wrong directory entirely. Spatie's own catch
 * here does not notify — it returns FAILURE and says nothing — so without this
 * the only signal would be an exit code nobody reads.
 */
class GuardedCleanupCommand extends CleanupCommand
{
    use RefusesAnUnavailableDestination;

    public function handle(): int
    {
        return $this->refuseIfDestinationIsUnavailable() ?? parent::handle();
    }

    protected function destinationUnavailableEvent(
        BackupDestinationUnavailableException $exception,
    ): object {
        return new CleanupHasFailed(
            $exception,
            (string) config('backup.destination_disk'),
            (string) config('backup.backup.name'),
        );
    }
}
