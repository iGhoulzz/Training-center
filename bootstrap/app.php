<?php

declare(strict_types=1);

use App\Domain\Staff\Exceptions\FileStorageException;
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

        /*
         * Storage failure → 503 (P1-T15, domain-integrity finding 3).
         *
         * The private disk is configured with throw => false, so every call site
         * converts a false return into FileStorageException. Nothing rendered it
         * and nothing caught it, so a full or read-only disk reached the
         * administrator as an unexplained 500 — the one failure where knowing
         * WHICH thing broke is the difference between a five-minute fix and an
         * outage nobody can diagnose.
         *
         * 503, not 500: the request was well-formed and the actor was entitled.
         * The server's storage is temporarily unable to accept it, which is what
         * 503 means and is the code that tells a caller to retry rather than to
         * change the request.
         *
         * THE EXCEPTION'S OWN MESSAGE IS NOT USED. It carries the disk name and
         * the stored path so an operator can read them in the log; a response
         * body is not a log, and neither value tells the administrator anything
         * they can act on. The rendered message is the translated explanation.
         *
         * This reaches HTTP paths only, which is exactly right:
         * PurgeDeletedFileJob must keep throwing, because throwing is what makes
         * Laravel retry a failed unlink rather than silently accepting that a
         * document the centre is no longer entitled to hold stayed on disk.
         * ExceptionRenderingTest asserts that the job still throws.
         */
        $exceptions->render(function (FileStorageException $e, Request $request) {
            $message = __('staff.storage_unavailable');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            return response($message, 503);
        });
    })->create();
