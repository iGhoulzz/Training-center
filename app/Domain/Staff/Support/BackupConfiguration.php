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
     * The archive directory and filename prefix when BACKUP_ARCHIVE_NAME is unset.
     *
     * ONE PLACE, because four things have to agree about it: config/backup.php's
     * env default, .env.example's documented value, docs/RESTORE.md's "with the
     * default configuration" note, and the test that keeps them consistent.
     * Written out four times, the runbook is the copy that goes stale — and a
     * runbook that names the wrong file is read during an incident.
     */
    public const DEFAULT_ARCHIVE_NAME = 'training-center';

    /**
     * What S3 needs before production may run.
     *
     * Demanded ONLY when S3 is the selected destination (P1-T17). A centre
     * backing up to a removable drive has no bucket, and requiring one would
     * refuse to boot a correctly configured install.
     *
     * The endpoint is on this list deliberately (P1-T15, G3-U1). Left blank it
     * constructs without complaint and the SDK resolves to a regional AWS host,
     * so a Backblaze or Wasabi deployment ships its archives to AWS with
     * credentials that will not authenticate — and nothing says so until a
     * restore. An AWS user writes one explicit line instead
     * (https://s3.eu-west-1.amazonaws.com); the silent case disappears.
     *
     * @var array<int, string>
     */
    private const REQUIRED_FOR_S3 = [
        'key',
        'secret',
        'bucket',
        // The S3 client refuses to sign a request without one, so an unset
        // region fails every upload at 01:30 rather than at boot.
        'region',
        'endpoint',
    ];

    /**
     * @throws RuntimeException when production is missing off-server backup configuration.
     */
    public static function assertReadyForProduction(string $environment): void
    {
        if ($environment !== 'production') {
            return;
        }

        $problems = [];

        /*
         * Encryption is not a property of the destination. A removable drive
         * left in a drawer is precisely the case where an unencrypted archive
         * matters most, so this is checked whichever disk is selected.
         */
        if (blank(config('backup.backup.password'))) {
            $problems[] = 'BACKUP_ARCHIVE_PASSWORD is not set, so archives would be written unencrypted.';
        }

        $disk = (string) config('backup.destination_disk');
        $driver = (string) config("filesystems.disks.{$disk}.driver");

        $problems = match ($driver) {
            's3' => array_merge($problems, self::s3Problems($disk)),
            'local' => array_merge($problems, self::removableDriveProblems($disk)),
            default => array_merge($problems, [
                "BACKUP_DISK is set to '{$disk}', which is not a configured backup destination. "
                .'Use backups_local or backups_s3.',
            ]),
        };

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            "Backups are not configured for production:\n  - ".implode("\n  - ", $problems)
            ."\nSee docs/RESTORE.md. Until this is fixed the install would run nightly backups "
            .'that store nothing recoverable.'
        );
    }

    /**
     * @return array<int, string>
     */
    private static function s3Problems(string $disk): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_FOR_S3,
            static fn (string $key): bool => blank(config("filesystems.disks.{$disk}.{$key}")),
        ));

        if ($missing === []) {
            return [];
        }

        return ['The S3 destination is missing: '.implode(', ', $missing)
            .'. Set the matching BACKUP_S3_* values.'];
    }

    /**
     * @return array<int, string>
     */
    private static function removableDriveProblems(string $disk): array
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (blank($root) || ! is_string($root)) {
            return ['BACKUP_LOCAL_PATH is not set, so there is no drive to write to.'];
        }

        $problems = [];

        /*
         * THE RULE T13 WROTE DOWN, NOW ENFORCED DIRECTLY RATHER THAN BY
         * FORBIDDING THE WORD 'local'.
         *
         * A backup on the machine it protects dies with it. A removable drive at
         * /mnt/backups survives the server; a folder under the project is the
         * same disk the application lives on, and the archive and the thing it
         * protects then fail together.
         *
         * Compared as normalised prefixes so that a path merely BEGINNING with
         * the same characters as the project — a sibling directory — is not
         * mistaken for one inside it.
         */
        $normalise = static fn (string $path): string => rtrim(
            str_replace('\\', '/', $path),
            '/',
        ).'/';

        if (str_starts_with($normalise($root), $normalise(base_path()))) {
            $problems[] = "The backup path ({$root}) is inside the application itself, so the "
                .'archive would live on the disk it exists to recover. Point BACKUP_LOCAL_PATH '
                .'at a removable or external drive, such as /mnt/backups.';
        }

        /*
         * AN UNMOUNTED DRIVE IS THE FAILURE THIS CATCHES. Without it the nightly
         * run writes into an empty mount point on the root filesystem, the
         * monitor finds those archives and reports healthy, and the drive
         * somebody carries off-site stays empty.
         */
        if (! is_dir($root)) {
            $problems[] = "The backup path ({$root}) does not exist. If this is a removable "
                .'drive, it is not mounted — archives would be written to the mount point on the '
                .'server instead, and the monitor would report them healthy.';
        } elseif (! is_writable($root)) {
            $problems[] = "The backup path ({$root}) is not writable by the application.";
        }

        return $problems;
    }
}
