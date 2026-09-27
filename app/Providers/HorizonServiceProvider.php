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

            if (is_string($mailer) && $this->usesNonDeliveringTransport($mailer)) {
                throw new InvalidArgumentException('MAIL_MAILER must use a delivering transport for Horizon alerts in production.');
            }
        }

        Horizon::routeMailNotificationsTo($alertEmail);
    }

    /**
     * Determine whether a mailer or any composite member cannot deliver mail.
     *
     * @param  array<string, true>  $visited
     */
    private function usesNonDeliveringTransport(string $mailer, array $visited = []): bool
    {
        if (isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $configuration = config("mail.mailers.{$mailer}");

        if (! is_array($configuration)) {
            return false;
        }

        $transport = $configuration['transport'] ?? null;

        if (in_array($transport, ['array', 'log'], true)) {
            return true;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $members = $configuration['mailers'] ?? [];

        if (! is_array($members)) {
            return false;
        }

        foreach ($members as $member) {
            if (is_string($member) && $this->usesNonDeliveringTransport($member, $visited)) {
                return true;
            }
        }

        return false;
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
