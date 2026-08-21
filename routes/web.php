<?php

declare(strict_types=1);

use App\Http\Controllers\Finance\ReceiptDownloadController;
use App\Http\Controllers\Staff\StaffCertificateDownloadController;
use App\Http\Controllers\Staff\StaffProfilePhotoController;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Private file delivery
|--------------------------------------------------------------------------
|
| Two routes serve bytes from the 'private' disk, which has no URL of its own.
| They are grouped so the session-integrity middleware CANNOT be applied to one
| and forgotten on the other — the failure this group exists to prevent.
| P1-T15's review found exactly that gap: both routes carried `web` and
| `throttle` and nothing else, and the stock `web` group has no
| AuthenticateSession, so a session survived the password reset meant to revoke
| it and kept streaming scanned identity documents by sequential id.
|
| The group adds session integrity ONLY. Authentication is still the
| controllers' business: each one refuses a guest with 403 rather than a
| redirect, so a file endpoint never tells an anonymous caller where to log in.
| Each also checks is_active and then the policy, per request, before touching
| the disk.
|
| Rate limits stay per route: a register page renders many photos at once, while
| a certificate download is deliberate and rare. Without them a compromised
| session becomes a bulk export at whatever speed the network allows.
*/
Route::middleware(AuthenticatePrivateFileSession::class)->group(function (): void {
    Route::get('staff-certificates/{certificate}/download', StaffCertificateDownloadController::class)
        ->middleware('throttle:60,1')
        ->name('staff.certificates.download');

    Route::get('staff-profiles/{profile}/photo', StaffProfilePhotoController::class)
        ->middleware('throttle:240,1')
        ->name('staff.profiles.photo');

    Route::get('receipts/{payment}/download', ReceiptDownloadController::class)
        ->middleware('throttle:60,1')
        ->name('finance.receipts.download');
});
