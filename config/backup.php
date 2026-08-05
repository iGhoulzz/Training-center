<?php

declare(strict_types=1);

use App\Domain\Staff\Support\BackupConfiguration;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| Values used in more than one place below (P1-T15, findings M3 and M4)
|--------------------------------------------------------------------------
*/

/*
 * THE ARCHIVE DIRECTORY, AND WHY IT IS NOT APP_NAME.
 *
 * This names the directory inside the bucket that archives are written to, AND
 * the one the monitor looks in. It used to be env('APP_NAME'), so renaming the
 * application — a cosmetic change with no documented backup implication —
 * silently started a NEW directory.
 *
 * Every consequence of that was invisible. Tonight's backup succeeds. The
 * monitor, looking up the same new name, finds that one fresh archive and
 * reports healthy. Cleanup never sees the old directory again, so nothing is
 * deleted and nothing is reported. Retention has effectively reset to one night,
 * and the first sign is a restore that finds two years of history missing.
 *
 * It is now a setting of its own with a fixed default, changed only by somebody
 * who means to change where backups live. docs/RESTORE.md tells the operator to
 * look for `training-center-*.zip`, and a test asserts the two agree.
 */
/*
 * A BLANK VALUE FALLS BACK, because env()'s second argument is a default for a
 * MISSING key only. A key that exists and is empty returns '' and sails straight
 * past it — the same trap that silently disabled the whole audit trail in
 * finding M1, and .env.example now ships this key, so a fresh checkout with the
 * value cleared is a realistic state rather than a hypothetical one.
 *
 * An empty archive name would put every backup in the bucket root and make the
 * monitor look there too, which is the M3 failure with no rename required.
 */
$configuredBackupName = env('BACKUP_ARCHIVE_NAME');
$backupName = is_string($configuredBackupName) && trim($configuredBackupName) !== ''
    ? trim($configuredBackupName)
    : BackupConfiguration::DEFAULT_ARCHIVE_NAME;

/*
 * The retention tiers, hoisted so the storage alert below can be sized from the
 * same numbers rather than from a literal that drifts away from them.
 */
$keepAllForDays = 30;
$keepDailyForDays = 60;
$keepWeeklyForWeeks = 8;
$keepMonthlyForMonths = 12;
$keepYearlyForYears = 2;

/*
 * How many archives the tiers above imply — a CONSERVATIVE UPPER BOUND.
 *
 * Two earlier versions of this comment were wrong in opposite directions: first
 * that the periods overlap and the sum over-estimates, then that the sum is
 * exact. Neither. DefaultStrategy builds each period where the previous one
 * ends, so the ranges are successive — but it then keeps one backup per
 * CALENDAR GROUP, not per elapsed unit:
 *
 *     $backupsPerPeriod['weekly']  = groupByDateFormat(..., 'YW');
 *     $backupsPerPeriod['monthly'] = groupByDateFormat(..., 'Ym');
 *     $backupsPerPeriod['yearly']  = groupByDateFormat(..., 'Y');
 *
 * A range of eight weeks can touch nine ISO weeks, twelve months thirteen
 * calendar months, and two years three calendar years, depending on where the
 * range happens to start. Replaying the algorithm against nightly backups gives
 * 30 + 60 + 9 + 13 + 3 = 115 rather than 112.
 *
 * So each calendar-grouped tier carries a +1. Over-estimating is the safe
 * direction here: this figure sizes a WARNING threshold, and setting that too
 * low is the defect being fixed.
 */
$retainedArchives = $keepAllForDays
    + $keepDailyForDays
    + ($keepWeeklyForWeeks + 1)
    + ($keepMonthlyForMonths + 1)
    + ($keepYearlyForYears + 1);

/*
 * What one archive weighs on this deployment: the whole database plus both
 * upload roots, encrypted. Deployment-specific, so it is a setting — a centre
 * holding thousands of scanned documents should raise it rather than let the
 * nightly alert start crying wolf.
 */
/*
 * Blank-safe for the same reason, and the consequence here is sharper: (int) ''
 * is 0, which would size the storage alert at zero megabytes and report every
 * backup unhealthy from the first night. A non-numeric value falls back too
 * rather than silently becoming 0.
 */
$configuredArchiveMegabytes = env('BACKUP_EXPECTED_ARCHIVE_MB');
$expectedArchiveMegabytes = is_numeric($configuredArchiveMegabytes) && (int) $configuredArchiveMegabytes > 0
    ? (int) $configuredArchiveMegabytes
    : 250;

/*
 * How much room to leave above the calculated steady state before the storage
 * check complains. Named rather than written inline so the test can pin the
 * exact relationship without copying a literal that would then drift.
 *
 * Two is deliberate and bounded at both ends by a test: less than that and a
 * deployment whose archives merely run larger than the estimate is paged about
 * nothing, much more and the check stops being able to see runaway growth at all.
 */
$storageAlertHeadroom = 2;

/*
 * WHICH DESTINATION IS IN USE (P1-T17).
 *
 * `backups_local` is a removable drive; `backups_s3` is S3-compatible storage.
 * Both are defined in config/filesystems.php so switching is an .env change
 * rather than a code change, and BackupConfiguration validates whichever one is
 * named — S3 credentials are demanded only when S3 is selected, and the drive
 * path is checked only when the drive is.
 */
$destinationDisk = (string) env('BACKUP_DISK', 'backups_local');

/*
 * A file the operator creates once on each prepared drive. Device identity
 * proves SOME filesystem is mounted; this proves it is the right one, so a
 * rotated-in drive that was never prepared does not quietly start receiving the
 * centre's records. Set it empty to disable the check.
 */
$volumeMarker = (string) env('BACKUP_VOLUME_MARKER', '.training-center-backup-volume');

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => $backupName,

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 */
                /*
                 * THE UPLOAD ROOTS, NOT THE WHOLE PROJECT.
                 *
                 * base_path() would sweep in the source tree, which git already
                 * holds, and .env, which would put production credentials in a
                 * third-party bucket. What is irreplaceable here is what users
                 * put in: the two storage roots below.
                 *
                 * storage/app/secure is the `private` disk — staff certificates
                 * and profile photos. It MUST be here: the database records only
                 * PATHS, so a database-only restore leaves every scanned
                 * credential permanently unrecoverable. Note it is deliberately
                 * NOT storage/app/private, which is the framework's default local
                 * root; see config/filesystems.php for why those are separate.
                 *
                 * storage/app/public is the `public` disk. Nothing writes to it
                 * in phase 1, so this backs up an empty directory today — and
                 * that is the point: the day something does write there, the
                 * backup already covers it rather than silently not.
                 */
                'include' => [
                    storage_path('app/secure'),
                    storage_path('app/public'),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 *
                 * Directories used by the backup process will automatically be excluded.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('framework'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * PATHS INSIDE THE ARCHIVE ARE RELATIVE TO THE PROJECT ROOT.
                 *
                 * Left null, the package stores every file under its absolute
                 * deployment path — /home/forge/training-center/storage/app/secure/…
                 * — so an archive taken on one server unpacks into a directory
                 * tree that only makes sense on that server, and the restore
                 * runbook's `restore/storage/app/secure` is simply wrong.
                 *
                 * base_path() makes the entries `storage/app/secure/…`, which is
                 * both what the runbook documents and what rsync back into a new
                 * install expects. BackupConfigurationTest asserts the two agree.
                 */
                'relative_path' => base_path(),
            ],

            /*
             * The names of the connections to the databases that should be backed up
             * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
             *
             * The content of the database dump may be customized for each connection
             * by adding a 'dump' key to the connection settings in config/database.php.
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'exclude_tables' => [
             *                'table_to_exclude_from_backup',
             *                'another_table_to_exclude'
             *            ]
             *       ],
             * ],
             *
             * If you are using only InnoDB tables on a MySQL server, you can
             * also supply the useSingleTransaction option to avoid table locking.
             *
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'useSingleTransaction' => true,
             *       ],
             * ],
             *
             * For a complete list of available customization options, see https://github.com/spatie/db-dumper
             */
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         *
         * Out of the box Laravel-backup supplies
         * Spatie\DbDumper\Compressors\GzipCompressor::class.
         *
         * You can also create custom compressor. More info on that here:
         * https://github.com/spatie/db-dumper#using-compression
         *
         * If you do not want any compressor at all, set it to null.
         */
        'database_dump_compressor' => null,

        /*
         * If specified, the database dumped file name will contain a timestamp (e.g.: 'Y-m-d-H-i-s').
         */
        'database_dump_file_timestamp_format' => null,

        /*
         * The base of the dump filename, either 'database' or 'connection'
         *
         * If 'database' (default), the dumped filename will contain the database name.
         * If 'connection', the dumped filename will contain the connection name.
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         *
         * If not specified, the file extension will be .archive for MongoDB and .sql for all other databases
         * The file extension should be specified without a leading .
         */
        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * The compression algorithm to be used for creating the zip archive.
             *
             * If backing up only database, you may choose gzip compression for db dump and no compression at zip.
             *
             * Some common algorithms are listed below:
             * ZipArchive::CM_STORE (no compression at all; set 0 as compression level)
             * ZipArchive::CM_DEFAULT
             * ZipArchive::CM_DEFLATE
             * ZipArchive::CM_BZIP2
             * ZipArchive::CM_XZ
             *
             * For more check https://www.php.net/manual/zip.constants.php and confirm it's supported by your system.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * The compression level corresponding to the used algorithm; an integer between 0 and 9.
             *
             * Check supported levels for the chosen algorithm, usually 1 means the fastest and weakest compression,
             * while 9 the slowest and strongest one.
             *
             * Setting of 0 for some algorithms may switch to the strongest compression.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            /*
             * DERIVED, so the directory, the filename and the runbook cannot
             * disagree (P1-T15, review of finding M3). This was a third
             * hardcoded copy of the same word: changing it would have left the
             * destination directory alone and quietly made docs/RESTORE.md —
             * which tells the operator to look for `training-center-*.zip` —
             * wrong.
             */
            'filename_prefix' => $backupName.'-',

            /*
             * A SEPARATE FAILURE DOMAIN, WHICH IS THE REAL RULE (P1-T17).
             *
             * This said "off-server only, `local` is deliberately absent" while
             * the only destination was a bucket. The property it was protecting
             * is not the driver: a backup that shares a fate with the thing it
             * protects is not a backup, because dying together is the scenario a
             * backup exists for.
             *
             * A REMOVABLE drive satisfies that and uses the local driver, so the
             * name check would have forbidden the very thing the centre asked
             * for. BackupConfiguration enforces the property instead — refusing
             * a path inside the project at boot, and refusing at backup time a
             * path that turns out to be on the application's own filesystem,
             * which is what an unmounted drive looks like.
             *
             * AppServiceProvider fails loudly in production when this disk has no
             * credentials, rather than letting the scheduler run nightly against
             * a bucket that was never configured.
             */
            'disks' => [
                $destinationDisk,
            ],

            /*
             * Determines whether to allow backups to continue when some targets fail instead of failing completely.
             */
            'continue_on_failure' => false,
        ],

        /*
         * The directory where the temporary files will be stored.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * The password to be used for archive encryption.
         * Set to `null` to disable encryption.
         */
        /*
         * REQUIRED IN PRODUCTION, and enforced in AppServiceProvider.
         *
         * The archive holds the whole database — students.national_id, dates of
         * birth, addresses — plus every scanned identity document on the private
         * disk. That leaves this server and sits in somebody else's storage; an
         * unencrypted copy there is a data breach waiting for a misconfigured
         * bucket policy.
         *
         * LOSING THIS PASSWORD MAKES EVERY BACKUP UNREADABLE. It belongs in a
         * password manager, not only in .env — the .env lives on the server the
         * backup exists to survive. See docs/RESTORE.md.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * The encryption algorithm to be used for archive encryption.
         * Set to 'none' to disable encryption.
         *
         * Supported: 'none', 'default', 'aes128', 'aes192', 'aes256'
         *
         * When set to 'default', we'll use AES-256 if available on your system.
         */
        'encryption' => 'default',

        /*
         * After creating the zip, verify it can be opened and contains files.
         * Recommended for critical backups but adds a small overhead.
         */
        /*
         * Opens the finished zip and confirms it contains files.
         *
         * The package calls the overhead small; the alternative is discovering a
         * truncated or empty archive during a restore, which is the one moment
         * there is no second copy to fall back on.
         */
        'verify_backup' => true,

        /*
         * The number of attempts, in case the backup command encounters an exception
         */
        /*
         * Three attempts, a minute apart.
         *
         * The failure this covers is transient: object storage rate-limiting, a
         * dropped connection mid-upload, a momentary DNS blip at 01:30. One
         * attempt turns any of those into a missing night, and the next chance is
         * 24 hours away.
         */
        'tries' => 3,

        /*
         * The number of seconds to wait before attempting a new backup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 60,
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     *
     * You can also use your own notification classes, just make sure the class is named after one of
     * the `Spatie\Backup\Notifications\Notifications` classes.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * CANNOT BE BLANK. The package validates this at boot and throws
             * InvalidConfig on a null address, so an unset variable does not mean
             * "no alerting" — it means the application does not start. Found by
             * BackupConfigurationTest rather than at deploy time.
             *
             * ?: RATHER THAN env()'s SECOND ARGUMENT. A default only applies when
             * the variable is ABSENT — a key present but empty, which is exactly
             * what .env.example ships, returns '' and sails past it. The result
             * was that a fresh checkout threw InvalidConfig on every backup
             * command. ?: falls back on empty as well as missing.
             * Production should point BACKUP_ALERT_EMAIL at a mailbox somebody
             * reads: the monitor schedule exists to notice a backup that stopped
             * running, and mail to an address nobody checks is the same as no
             * notification at all.
             *
             * Asserted never to be the package's your@example.com placeholder.
             */
            'to' => env('BACKUP_ALERT_EMAIL') ?: env('MAIL_FROM_ADDRESS') ?: 'backups@localhost',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            /*
             * If this is an empty string, the name field on the webhook will be used.
             */
            'username' => '',

            /*
             * If this is an empty string, the avatar on the webhook will be used.
             */
            'avatar_url' => '',
        ],

        /*
         * A generic webhook channel that POSTs JSON to a URL.
         * Useful for Mattermost, Microsoft Teams, or custom integrations.
         */
        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * The log channel used for backup activity messages.
     *
     * Set to a channel name defined in config/logging.php to use that channel.
     * Set to false to disable backup logging entirely.
     * Set to null to use the default log channel.
     */
    'log_channel' => null,

    /*
     * Here you can specify which backups should be monitored.
     * If a backup does not meet the specified requirements the
     * UnHealthyBackupWasFound event will be fired.
     */
    /*
     * What one archive is expected to weigh, in megabytes. Read by the storage
     * health check below and by the test that keeps the two consistent; exposed
     * as config so an operator can size it without editing the alert directly.
     */
    /*
     * The selected destination, read by BackupConfiguration and by the tests
     * that keep the destination and the monitor pointed at the same place.
     */
    'destination_disk' => $destinationDisk,

    'volume_marker' => $volumeMarker,

    'expected_archive_megabytes' => $expectedArchiveMegabytes,

    /*
     * The upper bound on retained archives derived above. Exposed so the test
     * can assert the alert threshold against it without restating the
     * calendar-boundary arithmetic and letting the two copies drift.
     */
    'retained_archives' => $retainedArchives,

    /*
     * The multiplier applied to the calculated steady state to get the storage
     * alert threshold. Exposed so BackupConfigurationTest can assert the exact
     * relationship rather than re-stating the number.
     */
    'storage_alert_headroom' => $storageAlertHeadroom,

    'monitor_backups' => [
        [
            // The same directory the archives are written to. Two settings that
            // must not drift: a monitor pointed elsewhere reports healthy forever.
            'name' => $backupName,
            /*
             * The disk backups are actually WRITTEN to. The package ships 'local'
             * here, which would health-check a disk this application never backs
             * up to — reporting healthy forever while the real destination sat
             * empty.
             */
            'disks' => [$destinationDisk],
            'health_checks' => [
                MaximumAgeInDays::class => 1,

                /*
                 * SIZED FROM THE RETENTION TIERS, NOT LEFT AT THE PACKAGE'S 5 GB
                 * (P1-T15, group 3 finding M4).
                 *
                 * The tiers keep roughly a hundred full archives, each holding
                 * the database AND both upload roots, so steady-state storage
                 * passes 5 GB within the first month or two. The nightly health
                 * check then fails every night, for ever, about a system that is
                 * working exactly as designed.
                 *
                 * A THRESHOLD BELOW NORMAL OPERATION IS WORSE THAN NO THRESHOLD.
                 * It fires constantly, people learn to ignore the backup alert,
                 * and the one signal this whole design rests on — "a backup did
                 * not happen" — is lost inside the noise it generates.
                 *
                 * The headroom multiplier below absorbs archives larger than the
                 * estimate before anyone is paged, while still catching genuine
                 * runaway growth. The cleanup ceiling stays null: this WARNS,
                 * and nothing deletes an archive to satisfy a number.
                 */
                MaximumStorageInMegabytes::class => $retainedArchives
                    * $expectedArchiveMegabytes
                    * $storageAlertHeadroom,
            ],
        ],

        /*
        [
            'name' => 'name of the second app',
            'disks' => ['local', 's3'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
        */
    ],

    'cleanup' => [
        /*
         * The strategy that will be used to cleanup old backups. The default strategy
         * will keep all backups for a certain amount of days. After that period only
         * a daily backup will be kept. After that period only weekly backups will
         * be kept and so on.
         *
         * No matter how you configure it the default strategy will never
         * delete the newest backup.
         */
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            /*
             * The number of days for which backups must be kept.
             */
            /*
             * A month of daily restore points. The spec calls a training centre's
             * payment history non-reconstructible, and a mistake discovered three
             * weeks later — a bad import, a wrong bulk edit — needs a backup from
             * before it, not from last night.
             */
            'keep_all_backups_for_days' => $keepAllForDays,

            /*
             * After the "keep_all_backups_for_days" period is over, the most recent backup
             * of that day will be kept. Older backups within the same day will be removed.
             * If you create backups only once a day, no backups will be removed yet.
             */
            'keep_daily_backups_for_days' => $keepDailyForDays,

            /*
             * After the "keep_daily_backups_for_days" period is over, the most recent backup
             * of that week will be kept. Older backups within the same week will be removed.
             * If you create backups only once a week, no backups will be removed yet.
             */
            'keep_weekly_backups_for_weeks' => $keepWeeklyForWeeks,

            /*
             * After the "keep_weekly_backups_for_weeks" period is over, the most recent backup
             * of that month will be kept. Older backups within the same month will be removed.
             */
            'keep_monthly_backups_for_months' => $keepMonthlyForMonths,

            /*
             * After the "keep_monthly_backups_for_months" period is over, the most recent backup
             * of that year will be kept. Older backups within the same year will be removed.
             */
            'keep_yearly_backups_for_years' => $keepYearlyForYears,

            /*
             * After cleaning up the backups remove the oldest backup until
             * this amount of megabytes has been reached.
             * Set null for unlimited size.
             */
            /*
             * NO DESTRUCTIVE CEILING. This is not a monitoring threshold — when
             * the destination exceeds it the cleanup DELETES the oldest archives
             * regardless of every retention setting above.
             *
             * The archives are full backups including the uploads, so at even a
             * couple of hundred megabytes of files, thirty daily copies pass 5 GB
             * and the promised 30 days quietly becomes however many fit. The
             * retention window is the policy; storage is a bill, and a bill is
             * not a reason to silently discard the restore point somebody needs.
             *
             * Growth is still visible: MaximumStorageInMegabytes under
             * monitor_backups WARNS without deleting anything.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],

        /*
         * The number of attempts, in case the cleanup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new cleanup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

];
