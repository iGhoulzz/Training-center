<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the signed-in user's language to the request.
 *
 * REGISTERED AS PANEL AUTH MIDDLEWARE, NOT IN THE WEB GROUP.
 *
 * The locale lives on users.locale, so there is nothing to read until the panel
 * has resolved who is asking. Running this in the web group would put it ahead
 * of Filament's Authenticate for every panel route — it would work today, only
 * because the panel happens to use the default session guard, and would break
 * silently the moment a panel is given a guard of its own. Sitting inside
 * authMiddleware, the dependency is stated rather than assumed.
 *
 * Guests are therefore never touched by this class at all: the login page, the
 * public site and every unauthenticated route render in config('app.locale').
 *
 * IT ALWAYS CALLS setLocale(), INCLUDING FOR AN INVALID VALUE.
 *
 * users.locale is a plain string(5) column. A hand-edited row, a restored dump,
 * or a locale dropped from SUPPORTED in a later release can all leave a value
 * this application cannot render. Skipping the call in that case would leave
 * whatever locale was last set standing — which, in a queue worker or an
 * octane-style long-lived process, is the PREVIOUS REQUEST'S locale, so one
 * user's stale language would leak into another user's page. Resetting to the
 * fallback explicitly makes the bad value produce English rather than a guess.
 */
class SetLocale
{
    /**
     * The locales this application can actually render.
     *
     * Arabic is listed from commit one even though lang/ar/ is still empty.
     * That is the point of the phase-4 plan: an Arabic user's pages already
     * resolve through the Arabic catalogue and fall back to English per key, so
     * phase 4 is a translation exercise rather than a wiring exercise. Filament
     * itself already ships Arabic, so 'ar' is not hypothetical — it flips the
     * panel chrome and the <html dir> today.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->user()?->locale;

        app()->setLocale(
            is_string($requested) && in_array($requested, self::SUPPORTED, strict: true)
                ? $requested
                : (string) config('app.fallback_locale'),
        );

        return $next($request);
    }
}
