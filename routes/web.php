<?php

declare(strict_types=1);

use App\Http\Controllers\Finance\ReceiptDownloadController;
use App\Http\Controllers\Finance\ReceiptPrintController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\ProgrammeController;
use App\Http\Controllers\Public\PublicationController;
use App\Http\Controllers\Public\PublicationDownloadController;
use App\Http\Controllers\Staff\StaffCertificateDownloadController;
use App\Http\Controllers\Staff\StaffProfilePhotoController;
use App\Http\Controllers\Staff\UpdateOwnLocaleController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\AuthenticateAccountSession;
use App\Http\Middleware\AuthenticatePrivateFileSession;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetPublicLocale;
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
|
| TWO LANGUAGES, ONE LIST OF ROUTES (P4-T18a). The routes are written once, in
| `$publicPages`, and registered twice: unprefixed and named `public.*` for English,
| under `/ar` and named `ar.public.*` for Arabic. The names differ because the
| Arabic route under the English name would overwrite the English entry in the
| router's name lookup (PublicRoute explains the rest). Registering one list twice
| is what makes the Arabic site complete by construction: a page added to the list
| is in both languages, and cannot be in one only. A route added to the list may
| not alter either group or its middleware.
|
| BOTH GROUPS CARRY THE SAME MIDDLEWARE, INCLUDING SetPublicLocale. It reads the
| route's name, sets the locale from it and touches no session, so it is safe in a
| group that has none. English carries it too, so the two lists are identical
| (PublicLocaleTest compares them from the router) and English is set explicitly,
| not inherited from the previous request in a long-lived worker.
*/
$publicMiddleware = ['throttle:public-site', SetPublicLocale::class];
$publicWithoutMiddleware = [
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
];

$publicPages = function (): void {
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
};

Route::name('public.')
    ->middleware($publicMiddleware)
    ->withoutMiddleware($publicWithoutMiddleware)
    ->group($publicPages);

Route::prefix('ar')
    ->name('ar.public.')
    ->middleware($publicMiddleware)
    ->withoutMiddleware($publicWithoutMiddleware)
    ->group($publicPages);

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
| The 80 mm receipt print page (phase 4, P4-T24)
|--------------------------------------------------------------------------
|
| Not a private file: it streams nothing from the 'private' disk. It is an HTML
| page rendered on demand from the payment's receipt snapshot, which is why it is
| not in the group above. It carries the SAME two middleware on purpose — session
| integrity, so a session revoked by a password reset stops printing receipts
| too, and the same 60-a-minute limit — and its controller refuses a guest with
| 403, an inactive user with 403 and an unauthorized user with 403 exactly as the
| download does.
|
| It is a group of its own, and not a fourth route in the group above, because
| no phase 4 task touches that group (the plan's seam table for this file).
*/
Route::middleware([AuthenticatePrivateFileSession::class, 'throttle:60,1'])->group(function (): void {
    Route::get('receipts/{payment}/print', ReceiptPrintController::class)
        ->name('finance.receipts.print');
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
|
| THE ARABIC ROUTES ARE IN THIS GROUP TOO (P4-T18a), under `/ar` and named
| `ar.verify.certificates.*`, so the headers and the limiter are not something an
| Arabic route has to remember. The four routes are written once and registered
| twice, as the public pages are. The limiter is keyed by the client's IP and
| nothing else (`certificate-verification`, AppServiceProvider), so the Arabic
| prefix draws on the same ten-a-minute budget as the English one: a second
| language is not a second chance to guess a reference. SetPublicLocale is in the
| group, so both prefixes carry the same list, and it sets the locale from the
| route's name without touching the session this group still needs.
*/
Route::middleware([VerificationResponseHeaders::class, 'throttle:certificate-verification', SetPublicLocale::class])
    ->group(function (): void {
        $verifierRoutes = function (): void {
            Route::get('verify/certificates', [VerifyCertificateController::class, 'form'])
                ->name('verify.certificates.form');

            Route::post('verify/certificates', [VerifyCertificateController::class, 'submit'])
                ->name('verify.certificates.submit');

            // The home page's verifier box, a read-only `method="get"` form (owner,
            // 2026-10-13). It runs the POST's own submit(), which reads `reference` from
            // the request, normalises it, redirects a well-formed value to the result
            // page and returns the uniform miss for anything else, so the reference
            // rule has no second copy. THE PATH STAYS OUTSIDE
            // `verify/certificates/{reference}`: that segment is deliberately
            // unconstrained and would read `lookup` as a reference.
            Route::get('verify/lookup', [VerifyCertificateController::class, 'submit'])
                ->name('verify.certificates.lookup');

            Route::get('verify/certificates/{reference}', [VerifyCertificateController::class, 'show'])
                ->name('verify.certificates.show');
        };

        $verifierRoutes();

        Route::prefix('ar')->name('ar.')->group($verifierRoutes);
    });

/*
|--------------------------------------------------------------------------
| The admin panel's language switch (phase 4, P4-T18a)
|--------------------------------------------------------------------------
|
| One POST that sets the SIGNED-IN user's own users.locale, through
| UpdateOwnLocaleAction, and redirects back. It is a route of its own and not a
| Filament page because the switch is a plain form in the panel's topbar
| (components/locale-switch.blade.php), rendered by AdminPanelProvider's render hook
| only while the flag `public_site.admin_locale_switch` is on.
|
| A PLAIN WEB ROUTE GETS NONE OF THE PANEL'S PROTECTIONS, so it is given them (review
| round): the panel wraps its pages in a session-integrity check, the forced password
| change and an access check, and this route is not one of its pages. Without them a
| session that an administrator had invalidated by resetting the password could still
| write, a user held on the password form could write, and a user the panel refuses
| could write, each appending to the append-only audit log. The same defect P1-T15
| found in the private-file routes.
|
| - AuthenticateAccountSession: a session opened before a password change is sent to
|   the panel login. Its own class, because PrivateFileAccessTest pins exactly the
|   groups that declare AuthenticatePrivateFileSession.
| - ForcePasswordChange: the panel's own hold, so a user who must change their
|   password is sent to that form and nothing is written.
| - throttle:10,1: every toggle that changes the language appends a row, so the rate
|   is the bound on how fast the log can grow.
| - The panel-access check is the controller's, with is_active, because it needs the
|   user: it answers a guest, a student and a user without access with a 403.
|
| It keeps the `web` group, so the session and the CSRF check are on: a POST without a
| token is refused. It is a group of its own and not a route in the private-file
| groups above, which no phase 4 task touches, and it streams nothing.
*/
Route::middleware([AuthenticateAccountSession::class, ForcePasswordChange::class, 'throttle:10,1'])
    ->group(function (): void {
        Route::post('account/locale', UpdateOwnLocaleController::class)
            ->name('account.locale.update');
    });
