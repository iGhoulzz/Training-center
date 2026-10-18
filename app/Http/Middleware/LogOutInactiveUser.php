<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the open session of an account that was deactivated while it was signed in
 * (P3-14, P4-T33).
 *
 * Filament's Authenticate refuses such an account with `abort(403)`, so the person
 * had a working page one moment and a bare error the next, with nothing to say why.
 * This sits in front of it, signs the account out, and sends the person to that
 * panel's sign-in page with the reason.
 *
 * REGISTERED TWICE, AND BOTH ARE NEEDED. In each panel's authMiddleware, before
 * Authenticate, it covers full-page requests. In persistentMiddleware it covers
 * Livewire: every filter, modal and save on a panel posts to Livewire's own route,
 * where route middleware does not run (ForcePasswordChange's registration explains
 * the same thing). A redirect from persistent middleware is what Livewire turns
 * into a navigation - a 403 there would be an error modal on a page the person has
 * been using a moment ago, and a request that wrote nothing.
 *
 * IT IMPLEMENTS AuthenticatesRequests ONLY TO BE SORTED BESIDE Authenticate.
 * Laravel re-sorts a route's middleware by a priority list, and Filament's
 * Authenticate is on it (through that same marker interface) while a plain
 * middleware is not: listed first in authMiddleware(), this class was still
 * placed AFTER Authenticate, behind the 403 it exists to pre-empt. Sharing the
 * marker gives it Authenticate's slot, and the registration order inside the slot
 * is kept. The interface has no methods; AccountRulesTest asserts the resulting
 * order on a real route of each panel.
 *
 * THE PANEL'S OWN GUARD, NOT $request->user(). Before Authenticate has run, the
 * default guard is still `web`. Asking the request would look for the wrong
 * session on the student panel and find nobody, and an inactive student would
 * reach Authenticate's 403 after all. Filament::auth() is the panel's guard.
 *
 * THE SIGN-OUT IS THE LOGOUT CONTROLLER'S, STEP FOR STEP: leave the guard,
 * invalidate the whole session, regenerate the CSRF token. That means an /admin
 * session in the same browser ends too, as StudentPanelProvider records for a
 * logout from either panel; ending too much errs the safe way. The Logout event
 * fires as it would for a deliberate sign-out, so the activity log shows a
 * `logged_out` entry for the account.
 *
 * THE REASON TRAVELS IN THE URL (see Login). A notification flashed into the
 * session would be consumed by the request Livewire's client makes on its own
 * while following the redirect.
 */
class LogOutInactiveUser implements AuthenticatesRequests
{
    /** The query parameter the login page reads. */
    public const REASON_QUERY = 'reason';

    /** The one value it acts on. */
    public const REASON_DEACTIVATED = 'deactivated';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Filament::auth();
        $user = $guard->user();

        // Nobody signed in is Authenticate's business, and so is an active account.
        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        $guard->logout();

        session()->invalidate();
        session()->regenerateToken();

        return redirect()->to(Filament::getLoginUrl([self::REASON_QUERY => self::REASON_DEACTIVATED]));
    }
}
