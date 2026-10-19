<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PublicRoute;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the language the URL names to the request (P4-T18a).
 *
 * THE LANGUAGE IS IN THE ROUTE, BECAUSE THERE IS NOTHING ELSE TO READ IT FROM. The
 * public pages have no session, no cookie and no signed-in user, so a visitor's
 * choice cannot be remembered anywhere except the address they are at. `/ar/about`
 * is the Arabic page and `/about` the English one. That also makes each language a
 * URL of its own, which a cache can store and a search engine can index.
 *
 * THIS TOUCHES NO SESSION AND SETS NO COOKIE. It reads the matched route's name and
 * nothing else, so it is safe on the sessionless public group, and it is why the
 * verifier's routes can carry it without changing what they store.
 *
 * IT RUNS ON BOTH PREFIXES, English included, so that the two carry the same
 * middleware list (a test compares them from the router). English is then set
 * explicitly and not left to whatever the application's locale happened to be:
 * in a long-lived worker that would be the PREVIOUS request's locale.
 *
 * The rule for which locale a route name belongs to is PublicRoute::localeOf(), the
 * same one PublicRoute uses to build the other language's links, so the middleware
 * and the links cannot disagree about it.
 */
final class SetPublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(PublicRoute::localeOf($request->route()?->getName()));

        return $next($request);
    }
}
