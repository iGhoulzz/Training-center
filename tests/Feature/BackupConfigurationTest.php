<?php

declare(strict_types=1);

use App\Domain\Staff\Support\BackupConfiguration;
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

it('writes archives under the filename the runbook tells people to look for', function () {
    /*
     * THE FILENAME PREFIX, NOT THE BUCKET DIRECTORY.
     *
     * An earlier version of this test read backup.name, which is the DIRECTORY
     * inside the bucket. Filenames come from destination.filename_prefix, a
     * separate setting that was a third hardcoded copy of the same word — so
     * changing the prefix left this test green while docs/RESTORE.md, which
     * tells the operator to pick the newest `training-center-*.zip`, quietly
     * became wrong.
     *
     * The prefix is now derived from the same $backupName, and this asserts the
     * value an operator actually types into a file listing.
     */
    $runbook = (string) file_get_contents(base_path('docs/RESTORE.md'));
    $prefix = (string) config('backup.backup.destination.filename_prefix');

    expect($prefix)->not->toBe('', 'No filename prefix is configured.');

    expect(str_contains($runbook, $prefix))->toBeTrue(
        "The runbook does not mention the archive filename prefix ({$prefix}), so it sends "
        .'somebody hunting for a file that never exists.',
    );
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

    expect(str_contains($beforeFlat, 'BACKUP_S3_BUCKET'))->toBeTrue(
        'Nothing before the migrate command names the credentials it needs, so an operator '
        .'meets the refusal having already run it.',
    );

    expect(str_contains($beforeFlat, 'before you run this'))->toBeTrue(
        'Nothing before the migrate command says the credentials must be set first, which is '
        .'the whole of the ordering this finding is about.',
    );

    expect(str_contains($beforeFlat, 'Backups are not configured for production'))->toBeTrue(
        'The exact error is not quoted before the command, so an operator cannot recognise '
        .'the guard working and reads it as a broken restore.',
    );
});
