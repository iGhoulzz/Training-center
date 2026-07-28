<?php

declare(strict_types=1);

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
|   backup:run      make the archive and ship it off-server
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

Schedule::command('backup:run')
    ->daily()
    ->at('01:30')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);

Schedule::command('backup:monitor')
    ->daily()
    ->at('02:30')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);

Schedule::command('backup:clean')
    ->daily()
    ->at('03:00')
    ->timezone('Africa/Tripoli')
    ->createMutexNameUsing(fn (): string => 'framework/schedule-backup-pipeline')
    ->withoutOverlapping(180);
