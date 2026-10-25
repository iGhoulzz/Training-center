<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetGuestPanelLocale
{
    /**
     * Both panels share a session, but resolve their own guard before Authenticate.
     * A staff sign-in must not prevent a guest choice at the student login, or vice versa.
     * Authenticated requests retain SetLocale's existing ownership of users.locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Filament::auth()->check()) {
            return $next($request);
        }

        $requested = $request->query->has('locale')
            ? $request->query('locale')
            : $request->session()->get('guest_panel_locale');

        $locale = is_string($requested) && in_array($requested, SetLocale::SUPPORTED, strict: true)
            ? $requested
            : (string) config('app.fallback_locale');

        $request->session()->put('guest_panel_locale', $locale);
        app()->setLocale($locale);

        return $next($request);
    }
}
