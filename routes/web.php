<?php

declare(strict_types=1);

use App\Http\Controllers\Finance\ReceiptDownloadController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\ProgrammeController;
use App\Http\Controllers\Public\PublicationController;
use App\Http\Controllers\Public\PublicationDownloadController;
use App\Http\Controllers\Staff\StaffCertificateDownloadController;
use App\Http\Controllers\Staff\StaffProfilePhotoController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Http\Middleware\VerificationResponseHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| The public site (phase 4, P4-T11)
|--------------------------------------------------------------------------
|
| Home, about, programmes, contact, the publications index and the articles
| (T12, T13 and T15 add their routes INSIDE this group; none of them alters the
| group or its middleware). Every route here is named `public.` plus its own
| name, which is what PublicGroupTest finds them by.
|
| THESE PAGES HAVE NO SESSION, AND THAT IS BUILT, NOT ASSUMED. Every route in
| this file receives the `web` group, which starts a session and sets a cookie on
| every response, so an anonymous visitor to a page that varies by nobody would
| still be handed a Set-Cookie. Shared caches generally refuse to store a
| response that carries one, so the cookie was the blocker the application
| controls, and this group removes it. The four middleware removed below are the
| ones that start the session, share its error bag, check the CSRF token and
| queue cookies onto the response. `withoutMiddleware()` removes only ROUTE
| middleware, which these four are (the `web` group names them); it would not
| remove a global one.
|
| THIS DOES NOT MAKE THE PAGES CACHED, AND IT IS NOT MEANT TO. The responses
| still carry Symfony's default `Cache-Control: no-cache, private`, which a
| shared cache honours by not storing them. Choosing cache headers belongs with
| the cache layer, and the plan leaves that layer to deployment: the application
| adds no response cache in phase 4, and no `cache.headers` middleware here.
|
| WHAT THAT COSTS, AND WHY IT IS ACCEPTABLE. A page here can hold no form that
| submits data, no `@csrf`, and no `session()`, `old()` or `auth()` call, because
| the machinery behind each one is gone. So every route is GET, and contact is
| links rather than a form. The one form allowed is a read-only
| `method="get"` form, which only writes its fields into the URL: the
| publications search (owner, 2026-10-12, P4-T13). PublicGroupTest asserts the
| GET-only property over the router's own route list, and PublicSessionlessTest
| asserts that a response carries no cookie and that the views contain none of
| those constructs.
|
| NOT SESSIONLESS, AND UNTOUCHED: the private-file group and the verifier group
| below (the verifier's form posts and needs CSRF), and both Filament panels.
| PublicSessionlessTest asserts each of them still issues a session cookie, and
| that the ones that accept a POST still refuse one without a token. Removing
| the session from one group is one edit away from removing it from all of them.
|
| `public-site` is the named limiter registered in AppServiceProvider,
| referenced here so it protects something rather than reading as a control.
*/
Route::name('public.')
    ->middleware('throttle:public-site')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ])
    ->group(function (): void {
        Route::get('/', [PageController::class, 'home'])->name('home');
        Route::get('about', [PageController::class, 'about'])->name('about');
        Route::get('programmes', [ProgrammeController::class, 'index'])->name('programmes.index');
        Route::get('programmes/{course}', [ProgrammeController::class, 'show'])->whereNumber('course')->name('programmes.show');
        Route::get('contact', [PageController::class, 'contact'])->name('contact');

        // THE SLUG IS DELIBERATELY UNCONSTRAINED, as the verifier's reference below
        // is: a `->where()` would let a malformed value miss the route altogether,
        // skipping this group's limiter and taking a different path from an unknown
        // or unpublished slug. Every value reaches the controller, which answers
        // all three with the same 404. The download adds its own limiter on top of
        // the group's.
        Route::get('publications', [PublicationController::class, 'index'])->name('publications.index');
        Route::get('publications/{slug}', [PublicationController::class, 'show'])->name('publications.show');
        Route::get('publications/{slug}/download', PublicationDownloadController::class)
            ->middleware('throttle:publication-download')
            ->name('publications.download');
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
| The public certificate verifier (design section 7.1, P3-T08)
|--------------------------------------------------------------------------
|
| The first thing in this system that serves the open internet: no auth guard,
| no permission check, reachable by anyone who has a reference off a printed
| certificate. All four routes share one group for the same reason the
| private-file group above does — the rate limiter and the three disclosure
| headers must not be applicable to one route and forgotten on another, and a
| group is what makes that true for a route nobody has written yet as well as
| for the four that exist today (P4-T20b added the home page's lookup).
|
| THE {reference} SEGMENT IS DELIBERATELY UNCONSTRAINED. A `->where()` regex
| here would make a malformed value fail to match the route at all, and
| Laravel would render ITS OWN 404 page instead of the verifier's — a signal
| distinguishing "wrong shape" from "no such certificate" that design section
| 7.1 forbids. VerifyCertificateController::show() takes every value, whatever
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

        // The home page's verifier box, a read-only `method="get"` form (owner,
        // 2026-10-13). It runs the unchanged submit(), which reads `reference` from
        // the request, normalises it, redirects a well-formed value to the result
        // page and returns the uniform miss for anything else, so the reference
        // rule has no second copy. THE PATH STAYS OUTSIDE
        // `verify/certificates/{reference}`: that segment is deliberately
        // unconstrained and would read `lookup` as a reference.
        Route::get('verify/lookup', [VerifyCertificateController::class, 'submit'])
            ->name('verify.certificates.lookup');

        Route::get('verify/certificates/{reference}', [VerifyCertificateController::class, 'show'])
            ->name('verify.certificates.show');
    });
