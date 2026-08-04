<?php

declare(strict_types=1);

use App\Domain\Staff\Support\BackupConfiguration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Automated backups (P1-T13)
|--------------------------------------------------------------------------
|
| Three commands, in this order, every night:
|
|   backup:run      make the archive and write it to the backup destination
|   backup:monitor  check the destination actually holds a recent, sane backup
|   backup:clean    remove archives past the retention window
|
| RUN BEFORE CLEAN. Cleaning first would evaluate retention against yesterday's
| set and then add tonight's, so the window is permanently one day stale.
| Monitoring sits between them, reporting on the backup just taken rather than on
| whatever cleanup left behind.
|
| MONITORING IS THE POINT, NOT AN EXTRA. A failed run throws, and the package
| notifies on it. A run that never happens throws nothing at all — a cron entry
| silently not firing, a scheduler that died after a deploy, a full disk. All of
| those are indistinguishable from "everything is fine" unless something asks
| whether a recent backup actually exists. That is the failure mode this feature
| really has.
|
| TIMEZONE IS EXPLICIT. config/app.php runs the application in UTC and the centre
| does not; without this, 01:30 is 03:30 locally and drifts further whenever the
| offset changes, so the nightly dump starts competing with real users for the
| database. Stated rather than assumed.
|
| ONE MUTEX ACROSS ALL THREE, NOT ONE EACH.
|
| withoutOverlapping() derives its lock from the command by default, so the three
| would hold three separate locks and none would exclude the others — a backup
| still uploading at 03:00 would not stop the cleanup starting, and the cleanup
| would then evaluate retention against a destination mid-write. They are one
| pipeline and take one lock.
|
| The bound also protects each command from itself: a growing uploads directory
| eventually makes the archive take longer than the gap to the next window, and
| two concurrent backup:run processes would dump the same database twice and race
| each other writing to the bucket.
|
| The expiry is generous on purpose — a lock left behind by a killed process
| releases itself rather than blocking every subsequent night, and the whole
| pipeline shares one window.
*/

/*
 * The shared lock name is repeated literally rather than held in a constant.
 *
 * This file is re-evaluated on every application boot, and a file-scope const
 * throws "already defined" the second time — which in a test run is every test
 * after the first. BackupConfigurationTest asserts the three resolve to one
 * identical mutex, so a typo here fails the build rather than silently giving
 * cleanup its own lock.
 */

/*
 * THE DRIVE IS CHECKED HERE, NOT AT BOOT (P1-T17, review).
 *
 * Whether the removable drive is mounted is a transient fact about hardware.
 * Asserting it in AppServiceProvider::boot() — where the first version put it —
 * meant unplugging the USB stick would stop /admin from loading and block every
 * artisan command. A backup mechanism must not be able to halt the centre it
 * protects.
 *
 * Failing here stops the night's run and reports through the exception handler;
 * backup:monitor then finds no fresh archive and raises the unhealthy-backup
 * notification, which is the alert an operator acts on.
 *
 * It guards cleanup too: cleaning against an unmounted mount point would
 * evaluate retention over the wrong directory entirely.
 */
$assertDestinationReady = static fn () => BackupConfiguration::assertDestinationReady();

Schedule::command('backup:run')
    ->before($assertDestinationReady)
    ->daily()
    ->at('01:30')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);

Schedule::command('backup:monitor')
    ->before($assertDestinationReady)
    ->daily()
    ->at('02:30')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);

Schedule::command('backup:clean')
    ->before($assertDestinationReady)
    ->daily()
    ->at('03:00')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);

/*
|--------------------------------------------------------------------------
| File deletion reconciliation (P1-T15)
|--------------------------------------------------------------------------
|
| A pending_file_deletions row is the system's committed intent to destroy a
| file. PurgeDeletedFileJob retries five times and then lands in failed_jobs,
| leaving its receipt behind — and until this line existed, nothing ever read
| one. An exhausted job meant the document stayed on disk permanently, and in
| every nightly backup from then on, after the centre had decided to destroy it.
|
| HOURLY, BECAUSE THE THRESHOLD IS HOURLY. The command calls a receipt stale
| after an hour. Sweeping daily would mean a file the centre decided to destroy
| at 02:00 survives the whole day AND the 01:30 backup that follows it, which is
| the specific harm the finding describes.
|
| NO TIMEZONE, DELIBERATELY. The backup commands state one because "01:30" is a
| different instant in each zone. Every hour is swept whatever the offset, so a
| zone here would assert a preference that changes nothing.
|
| ITS OWN MUTEX, NOT THE BACKUP PIPELINE'S. Joining that lock was considered and
| rejected: it would let a slow or stuck nightly backup hold file deletion off
| for hours, and it buys nothing — ordinary purge jobs already run at arbitrary
| times, including mid-archive, so the sweep introduces no new interaction with
| the backup window. withoutOverlapping still applies to the sweep against
| itself, so a run that outlives its hour cannot have the next one re-dispatch
| the same oldest-first page underneath it.
|
| The expiry is generous for the same reason it is on the backup pipeline: a lock
| left behind by a killed process releases itself rather than blocking every
| subsequent hour.
*/

Schedule::command('files:sweep-pending-deletions')
    ->hourly()
    ->withoutOverlapping(120);
