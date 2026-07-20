<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Filament's own Login page throttles itself via rateLimit(5), so this
         * named limiter is not yet referenced by any route. It is registered
         * under Laravel's conventional name for the guarded routes the student
         * portal adds in phase 3.
         */
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * saveQuietly() is deliberate: P1-T12 adds activity logging, and a login
         * timestamp must not produce a spurious "user updated" audit entry.
         */
        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
