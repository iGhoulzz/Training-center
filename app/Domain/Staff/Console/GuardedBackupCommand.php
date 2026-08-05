<?php

declare(strict_types=1);

namespace App\Domain\Staff\Console;

use App\Domain\Staff\Exceptions\BackupDestinationUnavailableException;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * `backup:run`, refusing to write when the drive is not mounted.
 *
 * Replaces Spatie's command by name — see RefusesAnUnavailableDestination for
 * why the check belongs here rather than on the schedule.
 */
class GuardedBackupCommand extends BackupCommand
{
    use RefusesAnUnavailableDestination;

    public function handle(): int
    {
        return $this->refuseIfDestinationIsUnavailable() ?? parent::handle();
    }

    protected function destinationUnavailableEvent(
        BackupDestinationUnavailableException $exception,
    ): object {
        return new BackupHasFailed(
            $exception,
            (string) config('backup.destination_disk'),
            (string) config('backup.backup.name'),
        );
    }
}
