<?php

declare(strict_types=1);

namespace App\Domain\Staff\Console;

use App\Domain\Staff\Support\BackupConfiguration;
use RuntimeException;
use Spatie\Backup\Commands\ListCommand;

/**
 * `backup:list`, which still lists — but stops the table from lying.
 *
 * WHY THIS ONE IS WARNED AND NOT REFUSED
 * --------------------------------------
 * The other three commands refuse when the drive is out. This one must not: it
 * only reads, and listing what survived is exactly what somebody needs during an
 * incident. A read-only inspection command that blocks when things are broken is
 * useless at the only moment it matters.
 *
 * WHAT IT WAS QUIETLY GETTING WRONG
 * ---------------------------------
 * Spatie's "Reachable" column answers "could I list the configured directory",
 * which is not the same question as "is the drive mounted". Measured against an
 * ordinary empty directory standing in for an unmounted mount point:
 *
 *     Reachable ✅   Healthy ❌   0 backups
 *
 * and with a single stale archive left in that directory by an earlier unguarded
 * run — which is precisely the mess the other guards exist to prevent:
 *
 *     Reachable ✅   Healthy ✅   1 backup
 *
 * The server's own disk, certifying itself as a healthy backup, in green, to an
 * operator checking whether they can afford to rebuild the machine.
 *
 * So the volume checks run here too, and their answer is printed AFTER the
 * table: last on screen is what somebody acts on, and the point is to catch a
 * reader who has already seen a tick.
 */
class GuardedListCommand extends ListCommand
{
    public function handle(): int
    {
        $exitCode = parent::handle();

        $this->warnIfTheDriveIsNotThere();

        return $exitCode;
    }

    /**
     * Contradict the Reachable column when the volume checks disagree with it.
     *
     * Deliberately does not change the exit code and does not notify. Scripts
     * parse this command's status, and mailing the centre every time somebody
     * looks at a list is how an alert becomes noise that gets filtered.
     */
    private function warnIfTheDriveIsNotThere(): void
    {
        try {
            BackupConfiguration::assertDestinationReady();
        } catch (RuntimeException $exception) {
            $this->newLine();
            $this->error('Do not trust the Reachable column above.');
            $this->error($exception->getMessage());
            $this->error('The listing above is still accurate about what is in that directory.');
        }
    }
}
