<?php

declare(strict_types=1);

use App\Domain\Staff\Support\BackupConfiguration;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

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

it('writes backups off-server and nowhere else', function () {
    /*
     * THE ABSENCE OF 'local' IS THE ASSERTION.
     *
     * A backup on the same VPS as the application dies with it. Listing `local`
     * alongside the remote disk would let a misconfigured deployment keep
     * reporting success against the one destination that guarantees nothing.
     */
    $disks = config('backup.backup.destination.disks');

    expect($disks)->toContain('backups')
        ->and($disks)->not->toContain('local')
        ->and($disks)->toHaveCount(1);
});

it('points the backup disk at generic S3-compatible storage', function () {
    // Every value from BACKUP_S3_*, with an explicit endpoint, so Backblaze,
    // Wasabi, Spaces, Hetzner or MinIO all work without a code change.
    $disk = config('filesystems.disks.backups');

    expect($disk['driver'])->toBe('s3')
        ->and($disk)->toHaveKeys(['key', 'secret', 'region', 'bucket', 'endpoint']);
});

it('makes the backup disk throw rather than fail silently', function () {
    /*
     * The opposite of the application disks, deliberately. A write that silently
     * fails here produces a run that reports success and stores nothing — worse
     * than no backup, because it is trusted.
     */
    expect(config('filesystems.disks.backups.throw'))->toBeTrue();
});

it('monitors the disk backups are actually written to', function () {
    // The package ships 'local' here, which would health-check a disk this
    // application never writes to and report healthy forever.
    expect(config('backup.monitor_backups.0.disks'))->toBe(['backups']);
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
        'filesystems.disks.backups.key' => 'a-key',
        'filesystems.disks.backups.secret' => 'a-secret',
        'filesystems.disks.backups.bucket' => 'a-bucket',
        'filesystems.disks.backups.region' => null,
        'backup.backup.password' => 'a-long-archive-password',
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'filesystems.disks.backups.region');

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
| Production refuses to run without off-server backups
|--------------------------------------------------------------------------
*/

it('refuses to boot production without backup credentials', function () {
    // The whole point: an unset bucket does not stop the scheduler, it produces a
    // nightly run that stores nothing and reports success.
    config([
        'filesystems.disks.backups.key' => null,
        'filesystems.disks.backups.secret' => null,
        'filesystems.disks.backups.bucket' => null,
        'backup.backup.password' => null,
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'Backups are not configured for production');

it('refuses to boot production without an archive password', function () {
    // Credentials alone are not enough: an unencrypted archive of every national
    // ID and scanned document sitting in third-party storage is its own incident.
    config([
        'filesystems.disks.backups.key' => 'a-key',
        'filesystems.disks.backups.secret' => 'a-secret',
        'filesystems.disks.backups.bucket' => 'a-bucket',
        'filesystems.disks.backups.region' => 'a-region',
        'backup.backup.password' => null,
    ]);

    BackupConfiguration::assertReadyForProduction('production');
})->throws(RuntimeException::class, 'backup.backup.password');

it('boots production once everything is configured', function () {
    // The positive control. Without it the refusals above would hold just as well
    // for a guard that always throws.
    config([
        'filesystems.disks.backups.key' => 'a-key',
        'filesystems.disks.backups.secret' => 'a-secret',
        'filesystems.disks.backups.bucket' => 'a-bucket',
        'filesystems.disks.backups.region' => 'a-region',
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
        'filesystems.disks.backups.key' => null,
        'backup.backup.password' => null,
    ]);

    foreach (['local', 'testing', 'staging'] as $environment) {
        BackupConfiguration::assertReadyForProduction($environment);
    }

    expect(true)->toBeTrue();
});
