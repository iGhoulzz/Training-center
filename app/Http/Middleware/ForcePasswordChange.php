<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Route name verified against `php artisan route:list --path=admin`.
     * If it drifts, the page redirects to itself forever.
     */
    private const PAGE_ROUTE = 'filament.admin.pages.password-change';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(self::PAGE_ROUTE)) {
            return redirect()->to('/admin/password-change');
        }

        return $next($request);
    }
}
