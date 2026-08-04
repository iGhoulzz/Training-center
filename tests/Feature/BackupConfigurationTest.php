<?php

declare(strict_types=1);

use App\Domain\Staff\Support\BackupConfiguration;
use App\Domain\Staff\Support\BackupVolume;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| Backup configuration (P1-T13)
|--------------------------------------------------------------------------
|
| NOTHING HERE CONTACTS EXTERNAL STORAGE. These assert what the application is
| configured to do, not that a bucket answers — reaching out would make the suite
| depend on a third party being up and on credentials existing on a developer
| machine, and would prove nothing about the configuration that ships.
|
| Whether the credentials actually work is answered once at deploy time by the
| verification step in docs/RESTORE.md, and nightly from then on by
| backup:monitor. That division is deliberate: these tests catch a misconfigured
| repository, the monitor catches a broken environment, and neither can catch the
| other.
*/

/** The scheduled event for a given artisan command, or null. */
function scheduledBackupCommand(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

/*
|--------------------------------------------------------------------------
| What goes into the archive
|--------------------------------------------------------------------------
*/

it('backs up the database', function () {
    expect(config('backup.backup.source.databases'))->toContain('mysql');
});

it('backs up both upload roots, because the database only stores paths', function () {
    /*
     * storage/app/secure is the `private` disk — staff certificates and profile
     * photos. Restoring rows whose files are missing leaves every scanned
     * credential permanently unrecoverable, which is the failure spec section 11
     * names explicitly.
     */
    /*
     * CROSS-CHECKED AGAINST THE DISK CONFIGURATION, not a literal path.
     *
     * Asserting storage_path('app/secure') passes even after somebody moves the
     * private disk's root — the backup would then quietly cover a directory
     * nothing writes to while the real uploads went unbacked. Reading the roots
     * from the disks means the two cannot drift apart silently.
     */
    $include = config('backup.backup.source.files.include');

    expect($include)->toContain(config('filesystems.disks.private.root'))
        ->and($include)->toContain(config('filesystems.disks.public.root'));
});

it('stores archive paths relative to the project root', function () {
    /*
     * Left null, the package writes every entry under its absolute deployment
     * path, so an archive taken on one server unpacks into a tree that only makes
     * sense on that server — and docs/RESTORE.md, which says to rsync from
     * restore/storage/app/secure, is simply wrong.
     */
    expect(config('backup.backup.source.files.relative_path'))->toBe(base_path());

    // The path the runbook tells somebody to type, derived rather than repeated.
    $secure = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) config('filesystems.disks.private.root'));

    expect(str_replace(DIRECTORY_SEPARATOR, '/', $secure))->toBe('storage/app/secure');
});

it('does not sweep the whole project into the archive', function () {
    /*
     * base_path() would put .env — production database credentials, the app key,
     * the backup bucket's own secret — into third-party storage. The source tree
     * is in git and does not need backing up; what is irreplaceable is what
     * people uploaded.
     */
    expect(config('backup.backup.source.files.include'))->not->toContain(base_path());
});

/*
|--------------------------------------------------------------------------
| Where it goes, and that it cannot quietly go nowhere
|--------------------------------------------------------------------------
*/

it('writes backups to exactly one selected destination', function () {
    /*
     * REWRITTEN FOR P1-T17, AND THE RULE IS NARROWED RATHER THAN DROPPED.
     *
     * T13 asserted the absence of the string 'local', because at the time the
     * only destination was S3 and `local` meant the application's own storage
     * directory. That test was standing in for the real property: A BACKUP ON
     * THE MACHINE IT PROTECTS DIES WITH IT.
     *
     * The centre now backs up to a removable drive, which satisfies that
     * property while being a local-driver disk — so the name check would have
     * forbidden the very thing being built. The property itself is asserted
     * below and in the boot guard; here we only pin that exactly one
     * destination is configured, and that it is the one selected.
     */
    $disks = config('backup.backup.destination.disks');

    expect($disks)->toBe([config('backup.destination_disk')])
        ->and($disks)->toHaveCount(1);
});

it('offers both a removable-drive and an S3 destination', function () {
    /*
     * The structure the centre asked for: a local destination now, with the S3
     * one defined and ready rather than removed. Nothing in the pipeline — the
     * scheduler, the monitor, retention, encryption, the sweep — knows which is
     * selected, because spatie writes to a DISK.
     */
    expect(config('filesystems.disks.backups_local.driver'))->toBe('local')
        ->and(config('filesystems.disks.backups_s3.driver'))->toBe('s3');
});

it('makes both destinations throw rather than fail silently', function () {
    // The T13 property that does not change with the driver: a write that
    // silently fails produces a run reporting success and storing nothing.
    expect(config('filesystems.disks.backups_local.throw'))->toBeTrue()
        ->and(config('filesystems.disks.backups_s3.throw'))->toBeTrue();
});

it('still demands the S3 credentials when S3 is the selected destination', function () {
    /*
     * The other driver's requirements did not go away; they became conditional.
     * A deployment that switches BACKUP_DISK to S3 and forgets the bucket must
     * still refuse to boot.
     */
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => 'k',
        'filesystems.disks.backups_s3.secret' => 's',
        'filesystems.disks.backups_s3.region' => 'r',
        'filesystems.disks.backups_s3.bucket' => null,
        'filesystems.disks.backups_s3.endpoint' => 'https://example.test',
        'backup.backup.password' => 'a-password',
    ]);

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class);
});

it('demands an explicit S3 endpoint rather than silently meaning AWS', function () {
    /*
     * G3-U1, settled at last. A blank endpoint constructs without complaint and
     * the SDK then resolves to a regional AWS host — so a Backblaze or Wasabi
     * deployment ships its archives to AWS with credentials that will not
     * authenticate, and nothing says so until a restore.
     *
     * Requiring it costs an AWS user one explicit line
     * (https://s3.eu-west-1.amazonaws.com) and removes the silent case entirely.
     */
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => 'k',
        'filesystems.disks.backups_s3.secret' => 's',
        'filesystems.disks.backups_s3.region' => 'r',
        'filesystems.disks.backups_s3.bucket' => 'b',
        'filesystems.disks.backups_s3.endpoint' => '',
        'backup.backup.password' => 'a-password',
    ]);

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class);
});

it('does not demand S3 credentials when the drive is the destination', function () {
    /*
     * The asymmetry that makes the feature work: a centre backing up to a USB
     * drive has no bucket, and requiring one would refuse to boot a correctly
     * configured install.
     */
    $mount = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p1t17-mount';

    if (! is_dir($mount)) {
        mkdir($mount, 0777, true);
    }

    config([
        'backup.destination_disk' => 'backups_local',
        'filesystems.disks.backups_local.root' => $mount,
        'filesystems.disks.backups_s3.key' => null,
        'filesystems.disks.backups_s3.secret' => null,
        'filesystems.disks.backups_s3.bucket' => null,
        'filesystems.disks.backups_s3.region' => null,
        'backup.backup.password' => 'a-password',
    ]);

    BackupConfiguration::assertReadyForProduction('production');

    expect(true)->toBeTrue();
});

it('demands the archive password whichever destination is chosen', function () {
    // Encryption is not a property of the destination. A USB drive left in a
    // drawer is exactly the case where an unencrypted archive matters most.
    $mount = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p1t17-mount';

    if (! is_dir($mount)) {
        mkdir($mount, 0777, true);
    }

    config([
        'backup.destination_disk' => 'backups_local',
        'filesystems.disks.backups_local.root' => $mount,
        'backup.backup.password' => null,
    ]);

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class);
});

it('keeps the S3 destination ready even while the drive is in use', function () {
    /*
     * Repointed by P1-T17 from `backups` to `backups_s3`. The disk did not
     * change shape — every value still comes from BACKUP_S3_*, with an explicit
     * endpoint so Backblaze, Wasabi, Spaces, Hetzner or MinIO all work without a
     * code change — it simply has a name now, because there are two.
     *
     * Asserted even when it is NOT selected: "ready for S3 later" is the thing
     * the centre asked for, and an unselected disk that quietly rots would make
     * that false at the moment somebody needs it.
     */
    $disk = config('filesystems.disks.backups_s3');

    expect($disk['driver'])->toBe('s3')
        ->and($disk)->toHaveKeys(['key', 'secret', 'region', 'bucket', 'endpoint']);
});

it('monitors the disk backups are actually written to', function () {
    /*
     * The package ships 'local' here, which would health-check a disk this
     * application never writes to and report healthy forever. Now derived from
     * the selected destination rather than named, so switching BACKUP_DISK
     * cannot leave the monitor watching the disk nobody writes to.
     */
    expect(config('backup.monitor_backups.0.disks'))->toBe([config('backup.destination_disk')]);
});

/*
|--------------------------------------------------------------------------
| The archive itself
|--------------------------------------------------------------------------
*/

it('encrypts the archive', function () {
    /*
     * The archive holds the whole database — national IDs, dates of birth,
     * addresses — plus every scanned identity document, and it leaves this
     * server. The password comes from the environment; production refusing to
     * boot without it is asserted separately below.
     */
    expect(config('backup.backup.encryption'))->not->toBe('none')
        ->and(config('backup.backup.encryption'))->not->toBeNull();
});

it('verifies the archive after writing it', function () {
    // The alternative is discovering a truncated or empty zip during a restore,
    // which is the one moment there is no second copy.
    expect(config('backup.backup.verify_backup'))->toBeTrue();
});

it('keeps a month of daily restore points', function () {
    // A bad import or a wrong bulk edit is often noticed weeks later, and needs a
    // backup from BEFORE it rather than from last night.
    expect(config('backup.cleanup.default_strategy.keep_all_backups_for_days'))
        ->toBeGreaterThanOrEqual(30);
});

/*
|--------------------------------------------------------------------------
| The dump has to work as the user a managed host actually issues
|--------------------------------------------------------------------------
*/

it('dumps with options a restricted database user can use', function () {
    /*
     * --no-tablespaces is the one that matters. From MySQL 8, mysqldump reads
     * INFORMATION_SCHEMA.FILES unless told not to, and that needs the global
     * PROCESS privilege — which no sane managed host grants an application user.
     * Without this the backup works for whoever set it up as root and stops the
     * day the grants are tightened, at 01:30, with nobody watching.
     *
     * useSingleTransaction takes a consistent snapshot without locking tables,
     * so the nightly dump does not block the application; skipLockTables goes
     * with it because LOCK TABLES needs its own grant and is redundant under a
     * single transaction.
     */
    $dump = config('database.connections.mysql.dump');

    expect($dump['addExtraOption'])->toContain('--no-tablespaces')
        ->and($dump['useSingleTransaction'])->toBeTrue()
        ->and($dump['skipLockTables'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
*/

it('schedules the run, the monitor and the cleanup', function () {
    expect(scheduledBackupCommand('backup:run'))->not->toBeNull()
        ->and(scheduledBackupCommand('backup:monitor'))->not->toBeNull()
        ->and(scheduledBackupCommand('backup:clean'))->not->toBeNull();
});

it('schedules the monitor, because a backup that stops running throws nothing', function () {
    /*
     * A FAILED run raises an exception the package notifies on. A run that never
     * happens raises nothing at all — the cron entry not firing, a scheduler that
     * died after a deploy, a full disk. Without a monitor asking whether a recent
     * backup exists, every one of those is indistinguishable from success.
     */
    expect(scheduledBackupCommand('backup:monitor'))->not->toBeNull(
        'backup:monitor is not scheduled, so an absent backup is undetectable.',
    );
});

it('runs the backup before cleaning up old ones', function () {
    // Cleaning first evaluates retention against yesterday's set and then adds
    // tonight's, leaving the window permanently one day stale.
    $run = scheduledBackupCommand('backup:run');
    $clean = scheduledBackupCommand('backup:clean');

    expect($run->expression)->not->toBe($clean->expression);

    [, $runHour] = explode(' ', (string) $run->expression);
    [, $cleanHour] = explode(' ', (string) $clean->expression);

    expect((int) $runHour)->toBeLessThan((int) $cleanHour);
});

it('states the timezone rather than inheriting UTC', function () {
    /*
     * config/app.php runs the application in UTC and the centre does not. Without
     * an explicit zone, 01:30 is 03:30 locally and drifts whenever the offset
     * changes, so the nightly dump eventually competes with real users.
     */
    /*
     * THE EXPECTED ZONE, NOT MERELY "NOT NULL".
     *
     * Laravel fills an event's timezone from config when none is given, so
     * asserting non-null passes with the explicit call removed — it just reads
     * UTC instead. Verified: deleting ->timezone() failed nothing until this
     * asserted the value.
     */
    foreach (['backup:run', 'backup:monitor', 'backup:clean'] as $command) {
        expect(scheduledBackupCommand($command)->timezone)->toBe(
            'Africa/Tripoli',
            "{$command} does not state the centre's timezone and would drift against UTC.",
        );
    }
});

it('prevents a slow backup from overlapping the next one', function () {
    /*
     * A growing uploads directory eventually makes the archive take longer than
     * the gap to the next window. Two concurrent backup:run processes would dump
     * the same database twice and race each other writing to the bucket.
     */
    foreach (['backup:run', 'backup:monitor', 'backup:clean'] as $command) {
        expect(scheduledBackupCommand($command)->withoutOverlapping)->toBeTrue(
            "{$command} may run concurrently with itself.",
        );
    }
});

/*
|--------------------------------------------------------------------------
| Alerting
|--------------------------------------------------------------------------
*/

it('wires the production guard into the application boot', function () {
    /*
     * A TRIPWIRE, AND IT SAYS SO.
     *
     * The guard itself is unit-tested above, but nothing there proves it is ever
     * CALLED — deleting the line from AppServiceProvider would leave every one of
     * those tests green while production booted happily with no backups at all.
     *
     * Reading the provider's source is a blunt way to check that, and it cannot
     * tell whether the call is reachable. It catches the deletion, which is the
     * realistic mistake.
     */
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($provider)->toContain('BackupConfiguration::assertReadyForProduction');
});

it('resolves an alert address even when the env variable is present but empty', function () {
    /*
     * THE BUG A FRESH CHECKOUT HITS.
     *
     * env()'s second argument is a default for a MISSING key. A key that exists
     * and is empty — exactly what .env.example ships — returns '', sails past the
     * default, and the package throws InvalidConfig on every backup command,
     * backup:list included. The application does not start.
     *
     * Re-evaluates the config file with the variable blank, because the booted
     * config was resolved before this test ran and would not show it.
     */
    $original = $_ENV['BACKUP_ALERT_EMAIL'] ?? null;

    $_ENV['BACKUP_ALERT_EMAIL'] = '';
    putenv('BACKUP_ALERT_EMAIL=');

    try {
        $resolved = (require config_path('backup.php'))['notifications']['mail']['to'];
    } finally {
        if ($original === null) {
            unset($_ENV['BACKUP_ALERT_EMAIL']);
            putenv('BACKUP_ALERT_EMAIL');
        } else {
            $_ENV['BACKUP_ALERT_EMAIL'] = $original;
            putenv('BACKUP_ALERT_EMAIL='.$original);
        }
    }

    expect($resolved)->not->toBeEmpty(
        'A blank BACKUP_ALERT_EMAIL resolves to an empty address, which the package rejects '
        .'at boot — every backup command fails on a fresh checkout.',
    );
});

it('shares one mutex across the whole backup pipeline', function () {
    /*
     * withoutOverlapping() derives its lock from the command, so three commands
     * hold three separate locks and none excludes the others: a backup still
     * uploading at 03:00 does not stop the cleanup, which then evaluates
     * retention against a destination mid-write.
     */
    $names = collect(['backup:run', 'backup:monitor', 'backup:clean'])
        ->map(fn (string $command): string => scheduledBackupCommand($command)->mutexName())
        ->unique();

    expect($names)->toHaveCount(
        1,
        'The backup commands hold different locks, so cleanup can run while a backup uploads.',
    );
});

it('retries a failed backup rather than skipping the night', function () {
    // Rate-limiting, a dropped upload, a DNS blip at 01:30 — one attempt turns any
    // of those into a missing night, and the next chance is 24 hours away.
    expect(config('backup.backup.tries'))->toBeGreaterThan(1)
        ->and(config('backup.backup.retry_delay'))->toBeGreaterThan(0);
});

it('never deletes backups to stay under a storage ceiling', function () {
    /*
     * Not a monitoring threshold: exceeding it makes the cleanup delete the
     * OLDEST archives regardless of every retention setting above. Full archives
     * include the uploads, so thirty daily copies pass a few gigabytes quickly and
     * the promised 30 days silently becomes however many happen to fit.
     */
    expect(config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'))
        ->toBeNull();
});

it('requires a region before production may boot', function () {
    // The S3 client cannot sign a request without one, so an unset region fails
    // every upload at 01:30 rather than at boot.
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => 'a-key',
        'filesystems.disks.backups_s3.secret' => 'a-secret',
        'filesystems.disks.backups_s3.bucket' => 'a-bucket',
        'filesystems.disks.backups_s3.region' => null,
        'filesystems.disks.backups_s3.endpoint' => 'https://example.test',
        'backup.backup.password' => 'a-long-archive-password',
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'region');

/*
|--------------------------------------------------------------------------
| The removable drive (P1-T17, after review)
|--------------------------------------------------------------------------
|
| Split in two on purpose, and the split is the point.
|
| BOOT-TIME checks are things wrong in .env that no amount of waiting fixes.
| RUNTIME checks are facts about hardware. The first version asserted both at
| boot, so unplugging the USB stick would have stopped /admin from loading — a
| backup mechanism able to halt the centre it protects.
|
| The volume itself is reached through BackupVolume, which the suite substitutes.
| A test cannot mount a USB stick, and the first attempt's "positive control"
| used sys_get_temp_dir() — which on the machine it ran on is THE SAME DEVICE as
| the project, so the test certified the dangerous case as safe.
*/

/** A BackupVolume that answers whatever the test needs. */
function fakeVolume(array $answers = []): void
{
    $volume = new class($answers) extends BackupVolume
    {
        /** @param array<string, mixed> $answers */
        public function __construct(private array $answers) {}

        public function deviceIdFor(string $path): ?int
        {
            $isApplication = str_starts_with(
                str_replace('\\', '/', $path),
                str_replace('\\', '/', base_path()),
            );

            return $isApplication
                ? ($this->answers['applicationDevice'] ?? 1)
                : ($this->answers['destinationDevice'] ?? 2);
        }

        public function isDirectory(string $path): bool
        {
            return $this->answers['isDirectory'] ?? true;
        }

        public function isWritable(string $path): bool
        {
            return $this->answers['isWritable'] ?? true;
        }

        public function hasMarker(string $path, string $marker): bool
        {
            return $this->answers['hasMarker'] ?? true;
        }
    };

    app()->instance(BackupVolume::class, $volume);
}

/** Point the configuration at a drive, with everything else valid. */
function useDrive(string $root = '/mnt/backups'): void
{
    config([
        'backup.destination_disk' => 'backups_local',
        'filesystems.disks.backups_local.root' => $root,
        'backup.volume_marker' => '.training-center-backup-volume',
        'backup.backup.password' => 'a-long-archive-password',
    ]);
}

/*
| Boot: only what .env can be wrong about
*/

it('refuses to boot when the drive path is inside the application', function () {
    useDrive(storage_path('app'));

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class, 'inside the application itself');
});

it('refuses a drive path that only reaches the application through ..', function () {
    /*
     * THE BYPASS THE REVIEW FOUND, BUILT SO IT ACTUALLY BYPASSES.
     *
     * A first version used base_path('storage/../'), which the naive prefix
     * check already caught — the string still begins with the project path, so
     * deleting realpath() broke nothing and the test proved nothing. Mutation
     * testing said so.
     *
     * This one leaves the project directory textually and comes back:
     *
     *   …/worktrees/../worktrees/P1-T17/storage
     *
     * The string does not begin with …/worktrees/P1-T17/, so only resolving it
     * reveals that it lands inside the application. Every segment exists, which
     * realpath() requires.
     */
    $parent = dirname(base_path());

    useDrive($parent.'/../'.basename($parent).'/'.basename(base_path()).'/storage');

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class, 'inside the application itself');
});

/**
 * Link $link to $target, however this OS is willing to.
 *
 * PHP's symlink() needs a privilege on Windows that a normal account does not
 * have, but a junction does the same job for a directory and realpath() resolves
 * it identically. Without this fallback the symlink case below skips on every
 * Windows machine — which is how a test stops testing anything without ever
 * going red.
 */
function linkDirectory(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        return false;
    }

    $windowsTarget = str_replace('/', '\\', $target);
    $windowsLink = str_replace('/', '\\', $link);

    @exec(sprintf('mklink /J "%s" "%s" 2>nul', $windowsLink, $windowsTarget));

    return is_dir($link);
}

it('refuses a link that points into the application', function () {
    /*
     * The other shape of the containment bypass, and the one an operator is most
     * likely to create by accident: /mnt/backups is a link, and what it points
     * at is inside the project. Nothing in the string it is configured with says
     * so — only resolving it does.
     */
    $link = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tc-backup-link-'.uniqid();

    if (! linkDirectory(base_path('storage'), $link)) {
        test()->markTestSkipped('This OS permitted neither a symlink nor a junction.');
    }

    try {
        useDrive($link);

        expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
            ->toThrow(RuntimeException::class, 'inside the application itself');
    } finally {
        // Removes the link, never what it points at.
        is_link($link) ? @unlink($link) : @rmdir($link);
    }
});

it('refuses the plain .. form too', function () {
    // The simpler shape, kept because it is what somebody would actually type.
    useDrive(base_path('storage/../'));

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class, 'inside the application itself');
});

it('refuses the project root itself', function () {
    useDrive(base_path());

    expect(fn () => BackupConfiguration::assertReadyForProduction('production'))
        ->toThrow(RuntimeException::class, 'inside the application itself');
});

it('accepts a sibling directory whose name merely starts the same way', function () {
    /*
     * The over-broadening control: /…/P1-T17-backups is NOT inside /…/P1-T17,
     * and a prefix comparison without a trailing separator would say it is.
     */
    useDrive(base_path().'-backups');

    BackupConfiguration::assertReadyForProduction('production');

    expect(true)->toBeTrue();
});

it('does not check whether the drive is mounted at boot', function () {
    /*
     * THE FINDING THAT MATTERS MOST. If this throws, unplugging the USB stick
     * stops /admin from loading, blocks every artisan command, and takes the
     * centre offline to protect data nobody can reach anyway.
     */
    useDrive('/mnt/definitely-not-mounted-'.uniqid());

    BackupConfiguration::assertReadyForProduction('production');

    expect(true)->toBeTrue();
});

/*
| Runtime: the pipeline refuses, the application keeps serving
*/

it('refuses to back up onto the application own filesystem', function () {
    /*
     * The unmounted-drive case, and the one existence and writability cannot
     * see: /mnt/backups is an ordinary writable directory when nothing is
     * mounted on it, and archives written there die with the server while
     * backup:monitor reports them healthy.
     */
    useDrive();
    fakeVolume(['applicationDevice' => 7, 'destinationDevice' => 7]);

    expect(fn () => BackupConfiguration::assertDestinationReady())
        ->toThrow(RuntimeException::class, 'the drive is not mounted');
});

it('backs up to a drive on its own filesystem', function () {
    // The positive control, and it is now a control: a DIFFERENT device.
    useDrive();
    fakeVolume(['applicationDevice' => 7, 'destinationDevice' => 9]);

    BackupConfiguration::assertDestinationReady();

    expect(true)->toBeTrue();
});

it('refuses a mount point that does not exist', function () {
    useDrive();
    fakeVolume(['isDirectory' => false]);

    expect(fn () => BackupConfiguration::assertDestinationReady())
        ->toThrow(RuntimeException::class, 'does not exist');
});

it('refuses a drive that was never prepared for these backups', function () {
    /*
     * Device identity proves SOME filesystem is mounted, not that it is the
     * right one. Without the marker, rotating in an unprepared drive quietly
     * starts filling it with the centre's records.
     */
    useDrive();
    fakeVolume(['hasMarker' => false]);

    expect(fn () => BackupConfiguration::assertDestinationReady())
        ->toThrow(RuntimeException::class, 'volume marker');
});

it('skips the marker check when the centre has switched it off', function () {
    // The control for the marker: an empty setting disables it rather than
    // failing every night.
    useDrive();
    config(['backup.volume_marker' => '']);
    fakeVolume(['hasMarker' => false]);

    BackupConfiguration::assertDestinationReady();

    expect(true)->toBeTrue();
});

it('refuses a drive it cannot write to', function () {
    useDrive();
    fakeVolume(['isWritable' => false]);

    expect(fn () => BackupConfiguration::assertDestinationReady())
        ->toThrow(RuntimeException::class, 'not writable');
});

it('checks nothing about the volume when S3 is the destination', function () {
    // S3 reachability needs a network call, and a failed upload already fails
    // the run loudly. Asserting anything here would be theatre.
    config(['backup.destination_disk' => 'backups_s3']);

    BackupConfiguration::assertDestinationReady();

    expect(true)->toBeTrue();
});

it('guards every scheduled backup command with the readiness check', function () {
    /*
     * The wiring, without which every runtime check above is unreachable.
     * Reflection because Laravel keeps the callbacks protected; the assertion is
     * that each command has one and that it is the readiness check, proven by
     * invoking it against a destination that must be refused.
     */
    useDrive();
    fakeVolume(['isDirectory' => false]);

    foreach (['backup:run', 'backup:monitor', 'backup:clean'] as $command) {
        $event = scheduledBackupCommand($command);

        expect($event)->not->toBeNull("{$command} is not scheduled.");

        $callbacks = (new ReflectionProperty($event, 'beforeCallbacks'))->getValue($event);

        expect($callbacks)->not->toBeEmpty("{$command} runs with no destination check.");

        $refused = false;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (RuntimeException) {
                $refused = true;
            }
        }

        expect($refused)->toBeTrue(
            "{$command} would run against a destination that is not there.",
        );
    }
});

it('never ships the package placeholder alert address', function () {
    /*
     * The package defaults to your@example.com. Left in place, every failure
     * notification is addressed to nobody and the feature's whole warning
     * mechanism is decorative. Blank is honest; the placeholder is not.
     */
    expect(config('backup.notifications.mail.to'))->not->toBe('your@example.com');
});

it('notifies on failure and on an unhealthy backup', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications)->toHaveKey(BackupHasFailedNotification::class)
        ->and($notifications)->toHaveKey(UnhealthyBackupWasFoundNotification::class);
});

/*
|--------------------------------------------------------------------------
| Production refuses to run without a configured backup destination
|--------------------------------------------------------------------------
*/

it('refuses to boot production without backup credentials', function () {
    // The whole point: an unset destination does not stop the scheduler, it
    // produces a nightly run that stores nothing and reports success.
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => null,
        'filesystems.disks.backups_s3.secret' => null,
        'filesystems.disks.backups_s3.bucket' => null,
        'backup.backup.password' => null,
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'Backups are not configured for production');

it('refuses to boot production without an archive password', function () {
    // Credentials alone are not enough: an unencrypted archive of every national
    // ID and scanned document sitting in third-party storage is its own incident.
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => 'a-key',
        'filesystems.disks.backups_s3.secret' => 'a-secret',
        'filesystems.disks.backups_s3.bucket' => 'a-bucket',
        'filesystems.disks.backups_s3.region' => 'a-region',
        'filesystems.disks.backups_s3.endpoint' => 'https://example.test',
        'backup.backup.password' => null,
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'BACKUP_ARCHIVE_PASSWORD');

it('boots production once everything is configured', function () {
    // The positive control. Without it the refusals above would hold just as well
    // for a guard that always throws.
    config([
        'backup.destination_disk' => 'backups_s3',
        'filesystems.disks.backups_s3.key' => 'a-key',
        'filesystems.disks.backups_s3.secret' => 'a-secret',
        'filesystems.disks.backups_s3.bucket' => 'a-bucket',
        'filesystems.disks.backups_s3.region' => 'a-region',
        'filesystems.disks.backups_s3.endpoint' => 'https://example.test',
        'backup.backup.password' => 'a-long-archive-password',
    ]);

    BackupConfiguration::assertReadyForProduction('production');

    expect(true)->toBeTrue();
});

it('does not require backup credentials outside production', function () {
    /*
     * A developer machine and CI have no bucket and should need none. Requiring
     * credentials there pushes people to invent fake ones, which is how a real
     * deployment ends up pointed at somebody's test bucket.
     */
    config([
        'filesystems.disks.backups_s3.key' => null,
        'backup.backup.password' => null,
    ]);

    foreach (['local', 'testing', 'staging'] as $environment) {
        BackupConfiguration::assertReadyForProduction($environment);
    }

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The archive directory must not move when the app is renamed (finding M3)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| The archive directory must not move when the app is renamed (finding M3)
|--------------------------------------------------------------------------
|
| A NOTE ON TESTING env() HERE, BECAUSE IT COST A VACUOUS TEST.
|
| The usual trick in this file — write $_ENV, re-require the config, restore —
| WORKS ONLY FOR A KEY THAT WAS ABSENT AT BOOTSTRAP. Laravel's Env repository is
| immutable, so a key already loaded from .env keeps its original value no matter
| what is written to $_ENV afterwards. APP_NAME is in .env; ACTIVITYLOG_ENABLED
| and BACKUP_ALERT_EMAIL are not, which is why their tests are sound and a first
| attempt at the one below passed against the unfixed code.
|
| So the "APP_NAME must not reach this" half is asserted against the config
| SOURCE with comments stripped, which is the only thing that can fail, and the
| positive half is asserted behaviourally through a key that really is absent.
*/

it('does not derive any backup name from APP_NAME', function () {
    /*
     * P1-T15, group 3 finding M3. The destination directory inside the bucket
     * was env('APP_NAME'), so renaming the application — a cosmetic change with
     * no documented backup implication — silently starts a NEW directory.
     *
     * Every consequence is invisible. Tonight's backup succeeds. The monitor,
     * which looks up the same APP_NAME, finds that one fresh archive and reports
     * healthy. Cleanup never sees the old directory again, so nothing is deleted
     * and nothing is reported. Retention has effectively reset to one night, and
     * the first sign is a restore that finds two years of history missing.
     *
     * Comments are stripped before scanning, so the package's commented-out
     * second-application example does not count — and neither does this note.
     */
    $source = appSourceWithoutComments(config_path('backup.php'));

    expect(str_contains($source, 'APP_NAME'))->toBeFalse(
        'A backup name is still derived from APP_NAME, so renaming the application orphans '
        .'every archive already written.',
    );
});

/**
 * Boot the application in a SEPARATE PROCESS with extra environment variables,
 * and return the backup config it resolves.
 *
 * WHY A SUBPROCESS. Writing $_ENV inside the test only works for a key that was
 * absent when Laravel booted — its Env repository is immutable, so anything
 * already loaded from .env keeps its original value. That made the first version
 * of this test depend on the developer's .env: once BACKUP_ARCHIVE_NAME was
 * documented in .env.example, a normal fresh install copies it across and the
 * test failed before reaching any application behaviour.
 *
 * A child process is deterministic either way. The variable is present before
 * Laravel starts, and Dotenv's immutable loading leaves an existing environment
 * variable alone rather than overwriting it from .env — so the value passed here
 * wins on every machine.
 *
 * @param  array<string, string>  $environment
 * @return array<string, string>
 */
function backupConfigWithEnvironment(array $environment): array
{
    $code = sprintf(
        'require %s; $app = require %s; $app->make(%s)->bootstrap(); echo json_encode([%s]);',
        var_export(base_path('vendor/autoload.php'), true),
        var_export(base_path('bootstrap/app.php'), true),
        var_export(Kernel::class, true),
        implode(',', [
            "'name' => config('backup.backup.name')",
            "'monitor' => config('backup.monitor_backups.0.name')",
            "'prefix' => config('backup.backup.destination.filename_prefix')",
            "'expected_mb' => config('backup.expected_archive_megabytes')",
        ]),
    );

    $result = Process::env($environment)
        ->path(base_path())
        ->run([PHP_BINARY, '-r', $code]);

    expect($result->successful())->toBeTrue(
        'The child process could not boot the application: '.$result->errorOutput(),
    );

    /** @var array<string, string> $decoded */
    $decoded = json_decode(trim($result->output()), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('takes the archive directory from its own setting', function () {
    /*
     * The positive half of M3: the name really is controlled by
     * BACKUP_ARCHIVE_NAME, and the directory, the monitor and the filename
     * prefix all move together. They are separate settings that would otherwise
     * drift apart silently, leaving the monitor health-checking a directory
     * nothing writes to and reporting healthy for ever.
     */
    $config = backupConfigWithEnvironment(['BACKUP_ARCHIVE_NAME' => 'explicitly-named-archive']);

    expect($config['name'])->toBe('explicitly-named-archive')
        ->and($config['monitor'])->toBe('explicitly-named-archive')
        ->and($config['prefix'])->toBe('explicitly-named-archive-');
});

it('ignores APP_NAME when choosing the archive directory', function () {
    /*
     * The negative half, now provable rather than asserted against source alone.
     * A child process can set APP_NAME before boot, which is exactly what the
     * in-process technique could not do — and it is the scenario the finding is
     * about: somebody renames the application and every archive is orphaned.
     */
    $config = backupConfigWithEnvironment(['APP_NAME' => 'Renamed Centre']);

    expect($config['name'])->not->toBe('Renamed Centre')
        ->and($config['monitor'])->not->toBe('Renamed Centre')
        ->and($config['prefix'])->not->toBe('Renamed Centre-');
});

it('monitors the same directory it writes to', function () {
    // The two settings must agree as shipped, not only under an override.
    expect(config('backup.monitor_backups.0.name'))->toBe(config('backup.backup.name'));
});

it('tells the operator how the archive is named rather than assuming a default', function () {
    /*
     * THE RUNBOOK IS STATIC; THE ARCHIVE NAME IS A SETTING.
     *
     * An earlier version asserted that the runbook contained THIS machine's
     * configured filename prefix. On a deployment that legitimately sets
     * BACKUP_ARCHIVE_NAME — which .env.example explicitly invites — that
     * assertion fails, and it fails for a real reason: the document said "pick
     * the newest `training-center-*.zip`" while the bucket actually held
     * `preexisting-from-env-*.zip`. A runbook naming the wrong file is read
     * during an incident, by somebody who then concludes the backups are gone.
     *
     * Coupling a static document to a per-deployment value was the mistake. The
     * runbook now names the SETTING and states its default, so it is true on
     * every deployment, and this asserts exactly that — no reference to whatever
     * the current machine happens to be configured with.
     */
    $runbook = str_replace("\r\n", "\n", (string) file_get_contents(base_path('docs/RESTORE.md')));

    /*
     * SCOPED TO THE STEP THAT TELLS THEM WHICH FILE TO DOWNLOAD, not the whole
     * document. Mutation testing caught the looser version: deleting the setting
     * name from step 1 changed nothing, because the word still appeared in the
     * overview and in a later note — so the test passed while the one paragraph
     * an operator reads before picking a file had gone back to naming a single
     * hardcoded default. The same presence-versus-position mistake as the
     * migrate-ordering assertion below.
     */
    $stepStart = strpos($runbook, '### 1.');
    $stepEnd = strpos($runbook, '### 2.');

    expect($stepStart)->not->toBeFalse('The restore steps have been renumbered; this test needs updating.')
        ->and($stepEnd)->not->toBeFalse('The restore steps have been renumbered; this test needs updating.');

    $step = (string) preg_replace('/\s+/', ' ', substr($runbook, $stepStart, $stepEnd - $stepStart));

    expect(str_contains($step, 'BACKUP_ARCHIVE_NAME'))->toBeTrue(
        'The step that tells an operator which archive to download never names the setting '
        .'that decides the filename, so on a renamed deployment they have nothing to substitute.',
    );

    expect(str_contains($step, BackupConfiguration::DEFAULT_ARCHIVE_NAME.'-'))->toBeTrue(
        'That step does not state the default archive prefix, so an operator on a stock install '
        .'has no concrete name to look for.',
    );
});

it('keeps the documented default and the configured default in step', function () {
    /*
     * Four places have to agree about this name: the constant, the env default
     * in config, .env.example, and the runbook. The constant is the single
     * source; these two assertions catch the copies drifting from it.
     *
     * The config default is checked through a subprocess with the variable
     * explicitly unset, because on a machine that sets BACKUP_ARCHIVE_NAME the
     * booted config legitimately holds something else — the same coupling
     * mistake this whole test replaced.
     */
    $envExample = (string) file_get_contents(base_path('.env.example'));

    expect(str_contains($envExample, 'BACKUP_ARCHIVE_NAME='.BackupConfiguration::DEFAULT_ARCHIVE_NAME))
        ->toBeTrue('.env.example documents a different default from BackupConfiguration.');

    /*
     * Blank rather than absent, deliberately. .env.example now ships this key,
     * so "present but cleared" is the realistic way a deployment ends up without
     * a value — and env()'s default does not cover it, which is the M1 trap.
     * An empty name would put every archive in the bucket root.
     */
    $config = backupConfigWithEnvironment(['BACKUP_ARCHIVE_NAME' => '']);

    expect($config['name'])->toBe(
        BackupConfiguration::DEFAULT_ARCHIVE_NAME,
        'A blank BACKUP_ARCHIVE_NAME does not fall back to the documented default, so archives '
        .'would be written to the bucket root.',
    );
});

it('falls back when the expected archive size is blank or nonsense', function () {
    /*
     * The sharper half of the same trap. (int) '' is 0, so a cleared
     * BACKUP_EXPECTED_ARCHIVE_MB would size the storage alert at ZERO megabytes
     * and report every backup unhealthy from the first night — the M4 defect in
     * its most extreme form, reached without anyone touching the threshold.
     *
     * Non-numeric is covered too: a typo like "250MB" would otherwise cast to
     * 250 by luck, and something like "large" to 0.
     */
    foreach (['', 'not-a-number', '0', '-5'] as $value) {
        $config = backupConfigWithEnvironment(['BACKUP_EXPECTED_ARCHIVE_MB' => $value]);

        expect((int) $config['expected_mb'])->toBeGreaterThan(
            0,
            "BACKUP_EXPECTED_ARCHIVE_MB of '{$value}' produced a non-positive archive size, so "
            .'the storage alert would fire on every backup.',
        );
    }
});

it('derives the archive filename from the same name as the directory', function () {
    // Three places used to say "training-center" independently. Two of them
    // moving without the third is the drift this pins.
    expect(config('backup.backup.destination.filename_prefix'))
        ->toBe(config('backup.backup.name').'-');
});

/*
|--------------------------------------------------------------------------
| The storage alert must sit above normal operation (finding M4)
|--------------------------------------------------------------------------
*/

it('sets a storage alert above what its own retention policy stores', function () {
    /*
     * P1-T15, group 3 finding M4. The ceiling was the package's 5 GB default
     * while the retention tiers keep roughly 112 full archives, each holding the
     * database AND both upload roots. Steady-state storage is far above 5 GB, so
     * the nightly health check fails every night from the first month onward.
     *
     * A THRESHOLD BELOW NORMAL OPERATION IS WORSE THAN NO THRESHOLD. It fires
     * constantly, people learn to ignore the backup alert, and the one signal
     * this whole design rests on — "a backup did not happen" — is lost in noise.
     *
     * Derived from the tiers rather than compared against a literal, so changing
     * a retention setting without revisiting the alert fails here.
     */
    $strategy = config('backup.cleanup.default_strategy');

    $rawTierSum = $strategy['keep_all_backups_for_days']
        + $strategy['keep_daily_backups_for_days']
        + $strategy['keep_weekly_backups_for_weeks']
        + $strategy['keep_monthly_backups_for_months']
        + $strategy['keep_yearly_backups_for_years'];

    /*
     * Read rather than recomputed: the config accounts for calendar-boundary
     * straddle — an eight-week range can touch nine ISO weeks — and restating
     * that arithmetic here would just create a second copy to drift.
     *
     * It must not fall BELOW the naive tier sum, which is what pins the estimate
     * as conservative rather than optimistic.
     */
    $retainedArchives = (int) config('backup.retained_archives');

    /*
     * Exactly three more than the naive sum, one for each CALENDAR-GROUPED tier.
     *
     * DefaultStrategy keeps one backup per YW / Ym / Y group rather than per
     * elapsed unit, so an eight-week range can touch nine ISO weeks, twelve
     * months thirteen calendar months, and two years three calendar years. The
     * weekly, monthly and yearly tiers therefore each carry a +1; the daily
     * tiers group by Ymd and cannot straddle.
     *
     * Asserted as an equality rather than a floor, because a floor would pass
     * for an estimate with the +1s dropped again — which is the correction this
     * pins.
     */
    expect($retainedArchives)->toBe(
        $rawTierSum + 3,
        'The retained-archive estimate no longer allows for calendar-boundary straddle, so the '
        .'storage alert is sized from an optimistic figure.',
    );

    $expectedArchiveMb = (int) config('backup.expected_archive_megabytes');
    $ceiling = config('backup.monitor_backups.0.health_checks.'.MaximumStorageInMegabytes::class);

    expect($expectedArchiveMb)->toBeGreaterThan(
        0,
        'No expected archive size is configured, so the alert is not sized from anything.',
    )->and($ceiling)->toBeGreaterThanOrEqual(
        $retainedArchives * $expectedArchiveMb,
        "The storage alert fires below steady state: retention keeps about {$retainedArchives} "
        ."archives of ~{$expectedArchiveMb} MB each, so the nightly check would fail forever.",
    );
});

it('sizes the storage alert at exactly the configured headroom', function () {
    /*
     * THE EXACT RELATIONSHIP, NOT A RANGE.
     *
     * A first version only required the ceiling to sit between steady state and
     * 1 TB, which a headroom of twenty would have satisfied — and twenty means
     * the check can no longer see runaway growth at all. Pinned to the
     * configured multiplier, which is itself bounded below so the alert keeps
     * real headroom and above so it keeps its purpose.
     */
    $strategy = config('backup.cleanup.default_strategy');

    $rawTierSum = $strategy['keep_all_backups_for_days']
        + $strategy['keep_daily_backups_for_days']
        + $strategy['keep_weekly_backups_for_weeks']
        + $strategy['keep_monthly_backups_for_months']
        + $strategy['keep_yearly_backups_for_years'];

    /*
     * Read rather than recomputed: the config accounts for calendar-boundary
     * straddle — an eight-week range can touch nine ISO weeks — and restating
     * that arithmetic here would just create a second copy to drift.
     *
     * It must not fall BELOW the naive tier sum, which is what pins the estimate
     * as conservative rather than optimistic.
     */
    $retainedArchives = (int) config('backup.retained_archives');

    /*
     * Exactly three more than the naive sum, one for each CALENDAR-GROUPED tier.
     *
     * DefaultStrategy keeps one backup per YW / Ym / Y group rather than per
     * elapsed unit, so an eight-week range can touch nine ISO weeks, twelve
     * months thirteen calendar months, and two years three calendar years. The
     * weekly, monthly and yearly tiers therefore each carry a +1; the daily
     * tiers group by Ymd and cannot straddle.
     *
     * Asserted as an equality rather than a floor, because a floor would pass
     * for an estimate with the +1s dropped again — which is the correction this
     * pins.
     */
    expect($retainedArchives)->toBe(
        $rawTierSum + 3,
        'The retained-archive estimate no longer allows for calendar-boundary straddle, so the '
        .'storage alert is sized from an optimistic figure.',
    );

    $expectedArchiveMb = (int) config('backup.expected_archive_megabytes');
    $headroom = (int) config('backup.storage_alert_headroom');
    $ceiling = config('backup.monitor_backups.0.health_checks.'.MaximumStorageInMegabytes::class);

    expect($headroom)->toBeGreaterThanOrEqual(
        2,
        'Too little headroom: a deployment whose archives merely run larger than the estimate '
        .'would be paged about nothing.',
    )->and($headroom)->toBeLessThanOrEqual(
        4,
        'So much headroom that the check can no longer see runaway growth.',
    )->and($ceiling)->toBe(
        $retainedArchives * $expectedArchiveMb * $headroom,
        'The storage alert is not the configured multiple of the steady state the retention '
        .'tiers imply, so the two have drifted apart.',
    );
});

/*
|--------------------------------------------------------------------------
| The runbook must describe the retention it actually has (finding L6)
|--------------------------------------------------------------------------
*/

it('documents every retention tier in the runbook', function () {
    /*
     * P1-T15, group 3 finding L6. The runbook described 30 days, then daily for
     * 60, then monthly for a year — omitting the weekly and yearly tiers and
     * understating real retention by about two and a half years.
     *
     * Not harmless: the runbook's first instruction is to pick an archive from
     * BEFORE whatever went wrong, and somebody told they have one year will not
     * go looking for the two-year-old archive that exists.
     *
     * COMPLETE ROWS, NOT BARE NUMBERS. A first version searched for "8" and "2"
     * and stayed green when the weekly and yearly rows were deleted, because
     * those digits appear all over the document. Each row is rebuilt from config
     * and matched whole, so deleting one fails, and changing a tier without
     * updating the table fails too.
     */
    $strategy = config('backup.cleanup.default_strategy');
    $runbook = (string) file_get_contents(base_path('docs/RESTORE.md'));

    $rows = [
        'Every backup' => $strategy['keep_all_backups_for_days'].' days',
        'One per day' => $strategy['keep_daily_backups_for_days'].' days',
        'One per week' => $strategy['keep_weekly_backups_for_weeks'].' weeks',
        'One per month' => $strategy['keep_monthly_backups_for_months'].' months',
        'One per year' => $strategy['keep_yearly_backups_for_years'].' years',
    ];

    foreach ($rows as $label => $kept) {
        $row = "| {$label} | {$kept} |";

        expect(str_contains($runbook, $row))->toBeTrue(
            "The runbook is missing the retention row \"{$row}\", so it understates how far "
            .'back an archive can be recovered from.',
        );
    }
});

/*
|--------------------------------------------------------------------------
| The production guard gates the runbook's own steps (finding L7)
|--------------------------------------------------------------------------
*/

it('tells the operator to configure credentials before the migrate step', function () {
    /*
     * P1-T15, group 3 finding L7. BackupConfiguration::assertReadyForProduction()
     * is the first statement of AppServiceProvider::boot(), so it runs for EVERY
     * artisan command — including the `migrate` the runbook prescribes. On a
     * rebuilt server whose backup credentials are not in place yet, that step
     * fails with "Backups are not configured for production" in the middle of
     * restoring from a backup. The guard is correct and stays; the ordering has
     * to be written down.
     *
     * POSITION IS ASSERTED, NOT JUST PRESENCE. An operator works down a runbook
     * and runs each code block as they reach it, so a warning printed AFTER the
     * command is a warning they read once it has already failed. Earlier
     * versions of this test checked only that the words appeared somewhere in
     * the document, which they already did, and then only that they appeared
     * within the step — which the warning satisfied while sitting below the
     * command.
     */
    $runbook = str_replace("\r\n", "\n", (string) file_get_contents(base_path('docs/RESTORE.md')));

    $stepStart = strpos($runbook, '### 3.');
    $stepEnd = strpos($runbook, '### 4.');

    expect($stepStart)->not->toBeFalse('The restore steps have been renumbered; this test needs updating.')
        ->and($stepEnd)->not->toBeFalse('The restore steps have been renumbered; this test needs updating.');

    $step = substr($runbook, $stepStart, $stepEnd - $stepStart);

    /*
     * The fenced command, not a mention of it. The warning itself talks about
     * artisan commands, so searching for the bare words would find the warning
     * and compare it against itself.
     */
    $command = strpos($step, "```bash\nphp artisan migrate");

    expect($command)->not->toBeFalse('The migrate step no longer runs migrate.');

    /*
     * Flattened for the content checks: quote markers stripped, then whitespace
     * collapsed. Markdown wraps prose wherever the author stopped and this
     * passage is a blockquote, so a test matching raw text would be dictating
     * where the document wraps rather than checking what it says.
     */
    $before = substr($step, 0, $command);
    $beforeFlat = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*>\s?/m', '', $before));

    /*
     * UPDATED BY P1-T17. This required BACKUP_S3_BUCKET, which was the right
     * thing to name while a bucket was the only destination and is the wrong
     * thing now — an operator restoring onto a drive would be told to set a
     * value they do not have. What must be named is the setting that DECIDES,
     * and both branches it leads to.
     */
    foreach (['BACKUP_DISK', 'BACKUP_LOCAL_PATH', 'BACKUP_S3_'] as $needed) {
        expect(str_contains($beforeFlat, $needed))->toBeTrue(
            "Nothing before the migrate command mentions {$needed}, so an operator meets the "
            .'refusal without being told what to set for their destination.',
        );
    }

    expect(str_contains($beforeFlat, 'before you run this'))->toBeTrue(
        'Nothing before the migrate command says the credentials must be set first, which is '
        .'the whole of the ordering this finding is about.',
    );

    expect(str_contains($beforeFlat, 'Backups are not configured for production'))->toBeTrue(
        'The exact error is not quoted before the command, so an operator cannot recognise '
        .'the guard working and reads it as a broken restore.',
    );
});
