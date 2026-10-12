<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The isolation declaration DatabaseIsolationTest demands of every feature file.
// Nothing here writes a row.
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The public group: GET-only, throttled, and without a session (P4-T11)
|--------------------------------------------------------------------------
|
| `routes/web.php` gives every route the `web` group, so "no session for an
| anonymous visitor" is a property the public group has to build by removing
| four middleware. These tests read the routes the router actually holds, not
| the file that declared them, so a route added to the group tomorrow — by T12,
| T13 or T15 — is held to the same four properties without anybody editing this
| file.
*/

/**
 * The four middleware the public group removes from the `web` stack.
 *
 * Spelled out here, apart from routes/web.php, so deleting one from the route
 * file fails a test that did not read the route file for its answer.
 *
 * @return array<int, class-string>
 */
function publicGroupRemovedMiddleware(): array
{
    return [
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ];
}

/**
 * Every route that belongs to the public group, however it was found.
 *
 * THE UNION OF THREE QUESTIONS, NOT ONE. Selecting by name alone would let an
 * unnamed POST route slip into the group unseen, which is the exact shape of the
 * defect the GET-only assertion exists for. So a route counts if it is named
 * `public.*` (or T18's `ar.public.*` copies), OR carries the group's limiter, OR
 * has the session middleware removed. A route named `public.` that sits outside
 * the group is then caught by the middleware assertions rather than skipped.
 *
 * @return array<int, RoutingRoute>
 */
function publicGroupRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => preg_match('/^(ar\.)?public\./', (string) $route->getName()) === 1
            || in_array('throttle:public-site', $route->gatherMiddleware(), true)
            || in_array(StartSession::class, $route->excludedMiddleware(), true))
        ->values()
        ->all();
}

/**
 * A readable label for a route in a failure message.
 */
function publicRouteLabel(RoutingRoute $route): string
{
    return implode('|', $route->methods()).' '.$route->uri().' ['.($route->getName() ?? 'unnamed').']';
}

it('finds the public routes it asserts over', function () {
    // The control for every test below: a selector that matched nothing would
    // let each of them pass over an empty list.
    $names = array_map(fn (RoutingRoute $route): ?string => $route->getName(), publicGroupRoutes());

    expect($names)->toContain('public.home');
});

it('accepts only GET and HEAD on every public route', function () {
    $offenders = [];

    foreach (publicGroupRoutes() as $route) {
        if ($route->methods() !== ['GET', 'HEAD']) {
            $offenders[] = publicRouteLabel($route);
        }
    }

    expect($offenders)->toBeEmpty(
        "The public group has no session and no CSRF check, so it may hold only GET pages:\n  ".implode("\n  ", $offenders),
    );
});

it('applies the public-site limiter to every public route', function () {
    $offenders = [];

    foreach (publicGroupRoutes() as $route) {
        if (! in_array('throttle:public-site', $route->gatherMiddleware(), true)) {
            $offenders[] = publicRouteLabel($route);
        }
    }

    expect($offenders)->toBeEmpty(
        "These public routes are not throttled:\n  ".implode("\n  ", $offenders),
    );
});

it('removes the session, error-sharing, CSRF and queued-cookie middleware from every public route', function () {
    $offenders = [];

    foreach (publicGroupRoutes() as $route) {
        // Two questions, because they can disagree. `excludedMiddleware()` is what
        // the group declared; the router's resolved list is what a request will
        // actually run, after the `web` group is expanded and the removal applied.
        $declared = $route->excludedMiddleware();
        $effective = Route::resolveMiddleware($route->gatherMiddleware(), $declared);

        foreach (publicGroupRemovedMiddleware() as $class) {
            if (! in_array($class, $declared, true)) {
                $offenders[] = publicRouteLabel($route).' does not declare the removal of '.$class;
            }

            if (in_array($class, $effective, true)) {
                $offenders[] = publicRouteLabel($route).' would still run '.$class;
            }
        }
    }

    expect($offenders)->toBeEmpty(implode("\n  ", $offenders));
});

it('registers the public-site limiter as 120 a minute keyed by the request IP', function () {
    $callback = RateLimiter::limiter('public-site');

    expect($callback)->not->toBeNull('The public-site limiter is not registered at all.');

    $limit = $callback(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
    $other = $callback(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']));

    // One window, not an array of them: the key needs no window prefix, which the
    // certificate-verification limiter's comment explains.
    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(120)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('203.0.113.9')
        ->and($other->key)->toBe('203.0.113.10');
});

it('serves 120 requests a minute from one IP and refuses the next, through the real route', function () {
    for ($i = 1; $i <= 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('/')->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('/')->assertStatus(429);

    // Per IP, not global: a visitor who has made no request still gets the page.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])->get('/')->assertOk();
});
