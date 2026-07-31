<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Session integrity for the two private-file routes (P1-T15, group 2 finding 1).
 *
 * WHAT WAS MISSING.
 *
 * Staff certificates are scanned identity documents and profile photos are
 * personal. Both are served from a disk with no URL, by controllers that
 * re-authorize every request. What neither route had was any check that the
 * session presenting those credentials still represents a live login.
 *
 * `routes/web.php` gave them `web` and `throttle` and nothing else, and
 * Laravel's stock `web` group contains no AuthenticateSession — it is opt-in.
 * So the containment step this application actually supports, an administrator
 * resetting a compromised account's password, did not reach these two routes. A
 * stolen session kept streaming documents by sequential id at 60 a minute, long
 * after the credential behind it had been revoked and replaced.
 *
 * WHY THIS SUBCLASS EXISTS RATHER THAN FILAMENT'S.
 *
 * Filament's AuthenticateSession redirects to Filament::getLoginUrl(), which
 * resolves through the panel that is currently serving the request. These routes
 * are NOT panel routes — they are plain web routes — so that lookup depends on
 * ambient state that is not guaranteed to be there, and a failure to resolve it
 * would turn a clean refusal into a 500. The destination is written out.
 *
 * WHY IT DOES NOT AUTHENTICATE.
 *
 * The parent returns early when there is no session or no user, so a guest
 * passes straight through — deliberately. Both controllers already answer a
 * guest with 403 rather than a redirect, and that behaviour is deliberate too:
 * a file endpoint should not tell an anonymous caller where to log in, and
 * bouncing them to a login page would confirm the route exists. Adding `auth`
 * here would change a 403 into a 302 and weaken it.
 *
 * WHY IT DOES NOT CHECK is_active.
 *
 * Both controllers already do, before any policy check and before the disk is
 * touched. Repeating it here would put the same rule in two places, where they
 * can disagree. The regression tests assert the controllers keep doing it.
 */
class AuthenticatePrivateFileSession extends AuthenticateSession
{
    /**
     * Where an invalidated session is sent.
     *
     * A literal path, not route('login') and not Filament::getLoginUrl(): this
     * middleware runs outside any panel, and the redirect must not depend on
     * state these routes do not have.
     */
    protected function redirectTo($request): ?string
    {
        return '/admin/login';
    }
}
