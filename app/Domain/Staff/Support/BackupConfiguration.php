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
     * Refuse to boot production on a configuration that can never work.
     *
     * STATIC FACTS ONLY (P1-T17, review). This runs as the first statement of
     * AppServiceProvider::boot(), so it runs for every request and every artisan
     * command — which means anything checked here can take the whole application
     * down. The first version checked whether the removable drive was mounted
     * and writable from here, so UNPLUGGING THE USB STICK WOULD HAVE STOPPED
     * /admin FROM LOADING. A backup mechanism that can halt the centre it exists
     * to protect is worse than the risk it covers.
     *
     * What stays: the things that are wrong in the .env file itself and cannot
     * change while the process runs — a missing archive password, an unknown
     * destination, absent S3 credentials, a drive path pointing inside the
     * project. Those are deployment mistakes and should refuse loudly.
     *
     * What moved to assertDestinationReady(): mounted, right volume, writable.
     * Those are transient facts about hardware, and they now fail the BACKUP
     * PIPELINE instead of the application.
     *
     * @throws RuntimeException when production has no usable backup destination.
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
            'local' => array_merge($problems, self::drivePathProblems($disk)),
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
     * Refuse to run the backup pipeline against a destination that is not there.
     *
     * TRANSIENT FACTS (P1-T17, review). Called by the three guarded backup
     * commands, never at boot: the drive can be unplugged, rotated or fail at
     * any moment, and none of that should stop the centre from enrolling a
     * student.
     *
     * THE CALLER OWNS THE ALERT. This only reports what it found — the command
     * that catches it raises its own configured notification, because that is
     * what reaches whoever needs to plug the drive back in. An earlier version
     * of this docblock claimed backup:monitor would notice the next day, which
     * was doubly wrong: the refusal happened in a scheduler callback that ran
     * before the command started, so nothing was ever dispatched, and monitor
     * reading an unmounted mount point can report stale archives as healthy.
     * See RefusesAnUnavailableDestination.
     *
     * @throws RuntimeException when the destination is not usable right now.
     */
    public static function assertDestinationReady(): void
    {
        $disk = (string) config('backup.destination_disk');

        if ((string) config("filesystems.disks.{$disk}.driver") !== 'local') {
            // S3 reachability is not knowable without a network call, and a
            // failed upload already fails the run loudly. Nothing to check here.
            return;
        }

        $root = (string) config("filesystems.disks.{$disk}.root");
        $volume = app(BackupVolume::class);
        $problems = [];

        if (! $volume->isDirectory($root)) {
            $problems[] = "{$root} does not exist.";
        } else {
            /*
             * THE CHECK THAT ACTUALLY DETECTS AN ABSENT DRIVE.
             *
             * A mount point is an ordinary directory when nothing is mounted on
             * it: /mnt/backups exists, is writable, and silently belongs to the
             * server's own filesystem. Archives written there sit on the disk
             * they are meant to survive, and backup:monitor reports them healthy
             * because they are real files of the right age.
             *
             * Comparing device ids is what separates "the drive is here" from
             * "the mount point is here". Same device as the application means no
             * drive.
             */
            $applicationDevice = $volume->deviceIdFor(base_path());
            $destinationDevice = $volume->deviceIdFor($root);

            if ($destinationDevice === null) {
                $problems[] = "The filesystem holding {$root} could not be read.";
            } elseif ($destinationDevice === $applicationDevice) {
                $problems[] = "{$root} is on the same filesystem as the application, which "
                    .'means the drive is not mounted. Archives would be written to the mount '
                    .'point on the server and would die with it.';
            }

            $marker = (string) config('backup.volume_marker');

            /*
             * The second layer, and it answers a different question: device
             * identity proves SOME drive is mounted, not that it is the right
             * one. A rotated drive that was never prepared has no marker, so a
             * backup does not silently start filling somebody's photo stick.
             */
            if ($marker !== '' && ! $volume->hasMarker($root, $marker)) {
                $problems[] = "{$root} is missing the volume marker '{$marker}', so this is not "
                    .'a drive prepared for these backups. Run: touch '
                    .rtrim($root, '/\\').'/'.$marker;
            }

            if (! $volume->isWritable($root)) {
                $problems[] = "{$root} is not writable by the application.";
            }
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            "The backup destination is not ready:\n  - ".implode("\n  - ", $problems)
            // Deliberately not "tonight's backup did not run": all three
            // commands share this message, and two of them neither back up nor
            // run only at night.
            ."\nThe command stopped without touching the destination. See docs/RESTORE.md."
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
     * Static problems with a removable-drive path — the ones a deploy can fix.
     *
     * @return array<int, string>
     */
    private static function drivePathProblems(string $disk): array
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (blank($root) || ! is_string($root)) {
            return ['BACKUP_LOCAL_PATH is not set, so there is no drive to write to.'];
        }

        /*
         * RESOLVED BEFORE COMPARING (P1-T17, review). The first version compared
         * the strings as written, so a path containing `..` whose realpath was
         * the project directory sailed through, and a symlink or a difference in
         * Windows drive-letter casing would have done the same.
         *
         * realpath() returns false for a path that does not exist yet, which is
         * not a configuration error — an unmounted drive is the pipeline's
         * problem, not the boot guard's — so the unresolved value is used as a
         * fallback and the containment question is still asked of it.
         */
        $resolvedRoot = realpath($root) ?: $root;
        $resolvedBase = realpath(base_path()) ?: base_path();

        if (self::isInside($resolvedRoot, $resolvedBase)) {
            return ["The backup path ({$root}) resolves to {$resolvedRoot}, inside the "
                .'application itself, so the archive would live on the disk it exists to '
                .'recover. Point BACKUP_LOCAL_PATH at a removable drive, such as /mnt/backups.'];
        }

        return [];
    }

    /**
     * Is $path the same as, or beneath, $ancestor?
     *
     * Separators normalised and a trailing one appended to both, so a SIBLING
     * whose name merely begins with the same characters — /srv/app-backups
     * beside /srv/app — is not mistaken for a descendant. Windows comparison is
     * case-insensitive because its filesystem is.
     */
    private static function isInside(string $path, string $ancestor): bool
    {
        $normalise = static function (string $value): string {
            $value = rtrim(str_replace('\\', '/', $value), '/').'/';

            return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($value) : $value;
        };

        return str_starts_with($normalise($path), $normalise($ancestor));
    }
}
