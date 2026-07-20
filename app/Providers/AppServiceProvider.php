<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Staff\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
         * UserPolicy lives outside app/Policies, so Laravel's convention-based
         * policy discovery will not find it. Without this line every user
         * management check silently falls through to false.
         */
        Gate::policy(User::class, UserPolicy::class);

        /*
         * saveQuietly() is deliberate: P1-T12 adds activity logging, and a login
         * timestamp must not produce a spurious "user updated" audit entry.
         */
        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
