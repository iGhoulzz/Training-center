<?php

declare(strict_types=1);

use App\Http\Controllers\Finance\ReceiptDownloadController;
use App\Http\Controllers\Staff\StaffCertificateDownloadController;
use App\Http\Controllers\Staff\StaffProfilePhotoController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Http\Middleware\VerificationResponseHeaders;
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

/*
|--------------------------------------------------------------------------
| The public certificate verifier (design section 6.5, P3-T08)
|--------------------------------------------------------------------------
|
| The first thing in this system that serves the open internet: no auth guard,
| no permission check, reachable by anyone who has a reference off a printed
| certificate. All three routes share one group for the same reason the
| private-file group above does — the rate limiter and the three disclosure
| headers must not be applicable to one route and forgotten on another, and a
| group is what makes that true for a route nobody has written yet as well as
| for the three that exist today.
|
| THE {reference} SEGMENT IS DELIBERATELY UNCONSTRAINED. A `->where()` regex
| here would make a malformed value fail to match the route at all, and
| Laravel would render ITS OWN 404 page instead of the verifier's — a signal
| distinguishing "wrong shape" from "no such certificate" that design section
| 6.5 forbids. VerifyCertificateController::show() takes every value, whatever
| its shape, and decides.
|
| `certificate-verification` is the named limiter registered in
| AppServiceProvider — referenced here, which is the property whose absence
| got P1-T03's `login` limiter deleted.
*/
Route::middleware([VerificationResponseHeaders::class, 'throttle:certificate-verification'])
    ->group(function (): void {
        Route::get('verify/certificates', [VerifyCertificateController::class, 'form'])
            ->name('verify.certificates.form');

        Route::post('verify/certificates', [VerifyCertificateController::class, 'submit'])
            ->name('verify.certificates.submit');

        Route::get('verify/certificates/{reference}', [VerifyCertificateController::class, 'show'])
            ->name('verify.certificates.show');
    });
