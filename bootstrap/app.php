<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Escalation-guard exception → HTTP status mapping (P1-T04c).
         *
         * Guards 1, 2, and 4 are authorization refusals: the Actions raise
         * Illuminate\Auth\Access\AuthorizationException via Gate::authorize(),
         * which Laravel already renders as 403 — no mapping needed here.
         *
         * Guard 3 (the last active super admin may not be removed) is a
         * business-rule rejection, not a server error and not an authorization
         * failure — the actor may be fully entitled, yet the operation must be
         * refused. Map it to 422 so HTTP callers, and Filament/Livewire, treat
         * it as a handled validation-style failure rather than a 500. Task 5's
         * resource may also catch it directly to raise a Filament notification.
         */
        $exceptions->render(function (LastSuperAdminException $e, Request $request) {
            $message = $e->getMessage();

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return response($message, 422);
        });
    })->create();
