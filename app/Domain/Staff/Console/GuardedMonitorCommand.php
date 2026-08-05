<?php

declare(strict_types=1);

namespace App\Domain\Staff\Console;

use App\Domain\Staff\Exceptions\BackupDestinationUnavailableException;
use Illuminate\Support\Collection;
use Spatie\Backup\Commands\MonitorCommand;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * `backup:monitor`, refusing to vouch for a destination it cannot reach.
 *
 * Letting this one run unguarded is subtler than the other two: with the drive
 * out, the monitor reads an empty mount point. If archives were written there
 * during an earlier unguarded run it reports them healthy — the server's own
 * disk certifying itself as a backup.
 */
class GuardedMonitorCommand extends MonitorCommand
{
    use RefusesAnUnavailableDestination;

    public function handle(): int
    {
        return $this->refuseIfDestinationIsUnavailable() ?? parent::handle();
    }

    protected function destinationUnavailableEvent(
        BackupDestinationUnavailableException $exception,
    ): object {
        return new UnhealthyBackupWasFound(
            (string) config('backup.destination_disk'),
            (string) config('backup.backup.name'),
            // The shape BackupDestinationStatus::failureMessages() produces; the
            // notification templates index into these keys.
            new Collection([[
                'check' => 'DestinationIsAvailable',
                'message' => $exception->getMessage(),
            ]]),
        );
    }
}
