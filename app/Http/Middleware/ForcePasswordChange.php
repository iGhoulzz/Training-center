<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\PasswordChange;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Factory\Factory;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /*
     * THE PANEL IS RESOLVED PER REQUEST, NOT HARDCODED (P3-T01).
     *
     * These were two constants naming filament.admin.*, and the hold redirected
     * to a literal '/admin/password-change'. That was correct while one panel
     * existed and becomes a trap the moment a second does: a student issued a
     * temporary password would be held, then redirected into a panel
     * canAccessPanel() refuses them, while this guard's exemption never matched
     * the portal's own password route — so the page meant to be the way out was
     * unreachable. Exactly the trap the admin panel's version was built to avoid.
     *
     * The names come from Filament's own route APIs rather than a rebuilt
     * "filament.{id}.pages.{slug}" template, so a change in how Filament names
     * routes cannot silently desync this guard from the routes it is comparing
     * against.
     *
     * getCurrentOrDefaultPanel() rather than getCurrentPanel(): this middleware
     * only ever runs inside a panel's auth stack or as persistent middleware for
     * one of that panel's Livewire components, so a current panel is expected —
     * and Filament's own fallback is the default panel, which is admin. There is
     * no case where "no panel" should mean "exempt".
     */

    /**
     * The password page's route on the panel this request belongs to.
     *
     * If this ever fails to match the page's real route name, the page redirects
     * to itself forever — which is why it is derived rather than transcribed.
     */
    private function passwordPageRoute(): string
    {
        return PasswordChange::getRouteName(Filament::getCurrentOrDefaultPanel());
    }

    /**
     * The panel's logout route. POST only — a GET is refused by routing before
     * this guard is consulted at all.
     */
    private function logoutRoute(): string
    {
        return Filament::getCurrentOrDefaultPanel()->generateRouteName('auth.logout');
    }

    /*
     * THE ROUTE THIS GUARD SEES IS NOT ALWAYS THE ROUTE THAT WAS REQUESTED
     * (G1-U3).
     *
     * This middleware is persistent, so Livewire runs it for component updates
     * too. It does that by reading `memo.path` back OUT of the posted snapshot,
     * fabricating a request for that path, matching it against the router, and
     * pushing the fabricated request through the persistent middleware —
     * PersistentMiddleware::makeFakeRequest(). $request below is that fabrication
     * whenever the real request is a Livewire update.
     *
     * So `routeIs(PAGE_ROUTE)` answers "which page was this component rendered
     * on", not "what is being driven". Every component co-rendered on the
     * password form inherited the form's exemption and stayed drivable while the
     * account was supposed to be contained. Five did; layer 1 removed three of
     * them and the base layout's notification tray cannot be removed at all.
     *
     * The snapshot checksum HMACs the memo, so the path itself cannot be forged.
     * What has to be established is which COMPONENT the exemption is being spent
     * on, and the fabricated request cannot say.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        /*
         * Leaving is never contained.
         *
         * Route::post('/logout') is registered inside
         * Route::middleware($panel->getAuthMiddleware()), so this guard wraps it.
         * Layer 1 took the topbar away and with it the panel's own logout
         * control, so the form carries its own — and without this exemption that
         * button would redirect back to the form and the page would be a trap
         * with no exit but closing the browser.
         *
         * Scoped to the exact route name rather than a pattern, and that route is
         * POST-only and CSRF-protected. Logging out is not a privileged action;
         * it strictly reduces what the session can do.
         *
         * GATED ON THE REAL REQUEST NOT BEING A COMPONENT UPDATE. $request is a
         * fabrication whenever it is, so an exemption tested against it alone can
         * be spent by a snapshot whose memo merely CLAIMS `path=admin/logout` —
         * measured in review to drive the notification tray to a 200. Reaching it
         * needs APP_KEY to reseal the checksum, and nothing is ever dehydrated on
         * a logout response for a snapshot to be taken from, so it was not
         * reachable. This costs one call and removes the argument entirely: a
         * component update is never a logout.
         */
        if (! app(HandleRequests::class)->isLivewireRoute() && $request->routeIs($this->logoutRoute())) {
            return $next($request);
        }

        if (! $request->routeIs($this->passwordPageRoute())) {
            return $this->holdOnThePasswordForm();
        }

        /*
         * isLivewireRoute() reads request()->route() — the CONTAINER's request,
         * which is the real one. Livewire never rebinds the fabrication it hands
         * this middleware, so this is the one question the fabricated request
         * cannot answer wrongly. False here means an ordinary page load of the
         * form, which needs no further check.
         */
        if (app(HandleRequests::class)->isLivewireRoute() && ! $this->everyComponentIsThePasswordForm()) {
            return $this->holdOnThePasswordForm();
        }

        return $next($request);
    }

    /**
     * Does the posted payload drive the password form and nothing else?
     *
     * THE WHOLE PAYLOAD, NOT THE FIRST ENTRY. applyPersistentMiddleware() dedupes
     * by `method|path`, so this guard runs exactly once however many components
     * share the page's path — checking only the snapshot that triggered it would
     * wave everything behind that snapshot through.
     *
     * RESOLVED CLASSES, NOT NAME STRINGS. Comparing `memo.name` as text would
     * widen the exemption the day anything is registered under a name that
     * happens to match.
     *
     * FAIL-CLOSED, STATED ACCURATELY. handleUpdate() aborts 404 on an empty or
     * structurally malformed outer payload before update() fires
     * `snapshot-verified`, so those cases never reach here and a branch for them
     * would be dead code. What does reach here is a well-formed snapshot naming a
     * component that does not resolve; resolveComponentClass() throws for it, and
     * throwing denies.
     */
    private function everyComponentIsThePasswordForm(): bool
    {
        // The container's request, deliberately: the payload belongs to the real
        // Livewire post, not to the route fabricated from a snapshot's memo.
        $components = request()->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        /** @var Factory $factory */
        $factory = app('livewire.factory');

        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
                return false;
            }

            $snapshot = json_decode($component['snapshot'], associative: true);
            $memo = is_array($snapshot) && is_array($snapshot['memo'] ?? null) ? $snapshot['memo'] : [];
            $name = $memo['name'] ?? null;

            if (! is_string($name)) {
                return false;
            }

            try {
                if ($factory->resolveComponentClass($name) !== PasswordChange::class) {
                    return false;
                }
            } catch (ComponentNotFoundException) {
                return false;
            }
        }

        return true;
    }

    /**
     * A 302, not a 403.
     *
     * Utils::applyMiddleware() calls abort() on a RedirectResponse, so a redirect
     * surfaces correctly through Livewire's update endpoint and the open tab
     * follows it to the form. A 403 would surface as an error modal on a page the
     * user is not supposed to be looking at.
     */
    private function holdOnThePasswordForm(): RedirectResponse
    {
        return redirect()->route($this->passwordPageRoute());
    }
}
