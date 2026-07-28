<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use RuntimeException;

/**
 * Refuses to let production boot with backups that cannot work.
 *
 * WHY THIS IS LOUD RATHER THAN A COMMENT IN THE DEPLOYMENT DOCS
 * -------------------------------------------------------------
 * Every part of this feature fails quietly by nature. An unset BACKUP_S3_BUCKET
 * does not stop the scheduler; it produces a nightly run that reports success
 * against nothing. A missing BACKUP_ARCHIVE_PASSWORD does not raise anything
 * either; it writes the whole database and every scanned identity document into
 * third-party storage in plain text. Both states look exactly like a healthy
 * install until the day somebody needs a restore, or reads the bucket.
 *
 * So the check happens at boot, where it is impossible to miss, rather than at
 * 01:30 where nobody is watching. The application refuses to start rather than
 * running for months in a state where the backups do not exist.
 *
 * NON-PRODUCTION IS EXEMPT ON PURPOSE. A developer machine and CI have no bucket
 * and should need none; requiring credentials there would push people to invent
 * fake ones, which is how a real deployment ends up pointed at somebody's test
 * bucket. The environment check is the boundary.
 *
 * THIS IS NOT A CONNECTIVITY CHECK. It asserts the configuration is present, not
 * that the bucket is reachable — reaching out at boot would make every request
 * depend on a third party being up. Whether the credentials actually work is
 * answered by the post-deploy verification in docs/RESTORE.md and, from then on,
 * nightly by backup:monitor.
 */
final class BackupConfiguration
{
    /**
     * Values that must be present before production may run.
     *
     * The bucket and credentials are what make the destination off-server; the
     * archive password is what makes it safe to put there. A backup missing
     * either is not a backup this application is willing to pretend it has.
     *
     * @var array<int, string>
     */
    private const REQUIRED = [
        'filesystems.disks.backups.key',
        'filesystems.disks.backups.secret',
        'filesystems.disks.backups.bucket',
        'backup.backup.password',
    ];

    /**
     * @throws RuntimeException when production is missing off-server backup configuration.
     */
    public static function assertReadyForProduction(string $environment): void
    {
        if ($environment !== 'production') {
            return;
        }

        $missing = array_values(array_filter(
            self::REQUIRED,
            static fn (string $key): bool => blank(config($key)),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'Backups are not configured for production. Missing: '.implode(', ', $missing)
            .'. Set the BACKUP_S3_* credentials and BACKUP_ARCHIVE_PASSWORD, or this install '
            .'runs nightly backups that store nothing off-server. See docs/RESTORE.md.'
        );
    }
}
