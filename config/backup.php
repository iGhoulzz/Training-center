<?php

declare(strict_types=1);

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

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => env('APP_NAME', 'laravel-backup'),

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
            'filename_prefix' => 'training-center-',

            /*
             * OFF-SERVER ONLY. `local` is deliberately absent.
             *
             * A backup on the same VPS as the application dies with it, which is
             * the scenario a backup exists for. Spec section 11 requires
             * off-server storage, and listing `local` alongside it would let a
             * misconfigured deployment keep "succeeding" against the one disk
             * that guarantees nothing — a silent same-VPS fallback.
             *
             * AppServiceProvider fails loudly in production when this disk has no
             * credentials, rather than letting the scheduler run nightly against
             * a bucket that was never configured.
             */
            'disks' => [
                'backups',
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
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            /*
             * The disk backups are actually WRITTEN to. The package ships 'local'
             * here, which would health-check a disk this application never backs
             * up to — reporting healthy forever while the real destination sat
             * empty.
             */
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
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
            'keep_all_backups_for_days' => 30,

            /*
             * After the "keep_all_backups_for_days" period is over, the most recent backup
             * of that day will be kept. Older backups within the same day will be removed.
             * If you create backups only once a day, no backups will be removed yet.
             */
            'keep_daily_backups_for_days' => 60,

            /*
             * After the "keep_daily_backups_for_days" period is over, the most recent backup
             * of that week will be kept. Older backups within the same week will be removed.
             * If you create backups only once a week, no backups will be removed yet.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * After the "keep_weekly_backups_for_weeks" period is over, the most recent backup
             * of that month will be kept. Older backups within the same month will be removed.
             */
            'keep_monthly_backups_for_months' => 12,

            /*
             * After the "keep_monthly_backups_for_months" period is over, the most recent backup
             * of that year will be kept. Older backups within the same year will be removed.
             */
            'keep_yearly_backups_for_years' => 2,

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
