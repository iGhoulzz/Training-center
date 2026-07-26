<?php

declare(strict_types=1);

use App\Http\Controllers\Staff\StaffCertificateDownloadController;
use App\Http\Controllers\Staff\StaffProfilePhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Staff certificate downloads (P1-T06b).
 *
 * The 'private' disk has no URL and no route of its own, deliberately. This is
 * the one address that serves it, and the controller authorizes every request
 * through StaffCertificatePolicy::view() — see the controller for why a signed
 * URL is not used as the gate.
 *
 * Rate limited because it is an authenticated endpoint that reads from disk;
 * without a limit, a compromised session becomes a bulk export of every scanned
 * identity document at whatever speed the network allows.
 */
Route::get('staff-certificates/{certificate}/download', StaffCertificateDownloadController::class)
    ->middleware('throttle:60,1')
    ->name('staff.certificates.download');

/*
 * Inline staff avatars use the same private-disk rule as certificates. The
 * higher limit allows one register page to render many photos without turning
 * a normal refresh into throttling; authorization still runs per image request.
 */
Route::get('staff-profiles/{profile}/photo', StaffProfilePhotoController::class)
    ->middleware('throttle:240,1')
    ->name('staff.profiles.photo');
