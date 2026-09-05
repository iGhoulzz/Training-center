<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three headers every public verifier response must carry (design section
 * 7.2, P3-T08).
 *
 * WHY THESE THREE, ON EVERY RESPONSE FROM THE GROUP
 * ---------------------------------------------------
 * The reference appears in the URL and, once verified, unlocks a printed name
 * and course — the first personal data this application exposes to the open
 * internet without any authentication at all.
 *
 *   - `Cache-Control: private, no-store` — a shared cache or browser history
 *     entry must not retain a page naming a specific student.
 *   - `Referrer-Policy: no-referrer` — a link the page might ever carry (there
 *     is none today, but a future one) must not hand the reference to whatever
 *     origin it points at via the Referer header.
 *   - `X-Robots-Tag: noindex, nofollow, noarchive` — a search engine must not
 *     crawl, follow links from, or cache a page that exists only to answer one
 *     specific person's question about one specific certificate.
 *
 * THE WIRE VALUE OF Cache-Control READS BACK ALPHABETISED, AND THAT IS FINE.
 * Symfony's `ResponseHeaderBag` intercepts every write to this specific header
 * and rebuilds it from a directive map, sorted (`HeaderBag::getCacheControlHeader()`
 * calls `ksort()`) — measured directly, setting `private, no-store` here
 * produces `no-store, private` on the response. RFC 7234 gives Cache-Control
 * directive order no meaning, so this is not a defect; VerificationHeadersTest
 * asserts both directives are present via `hasCacheControlDirective()` rather
 * than the literal string, for exactly this reason.
 *
 * APPLIED ON THE ROUTE GROUP, NOT PER ROUTE.
 * ---------------------------------------------------
 * `routes/web.php`'s private-file group is the precedent this follows: a
 * middleware needed on every route of a feature belongs on the group, so a
 * route added later inherits it automatically rather than depending on
 * whoever writes it remembering to repeat three header names.
 */
final class VerificationResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
