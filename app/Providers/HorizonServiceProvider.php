<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $alertEmail = config('horizon.alert_email');

        if (($alertEmail === null || $alertEmail === '') && ! app()->isProduction()) {
            return;
        }

        if (! is_string($alertEmail) || filter_var($alertEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('HORIZON_ALERT_EMAIL must be a valid email address.');
        }

        if (app()->isProduction()) {
            $mailer = config('mail.default');
            $transport = is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;

            if (in_array($transport, ['array', 'log'], true)) {
                throw new InvalidArgumentException('MAIL_MAILER must use a delivering transport for Horizon alerts in production.');
            }
        }

        Horizon::routeMailNotificationsTo($alertEmail);
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null
            && $user->is_active
            && $user->can('view_any_activity'));
    }
}
