<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(
                in: app_path('Domain/Enrollment/Filament/Resources'),
                for: 'App\Domain\Enrollment\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Domain/Finance/Filament/Resources'),
                for: 'App\Domain\Finance\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Domain/Staff/Filament/Resources'),
                for: 'App\Domain\Staff\Filament\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(in: app_path('Domain/Finance/Filament/Pages'), for: 'App\Domain\Finance\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            /*
             * SetLocale sits between the two deliberately (P1-T14).
             *
             * AFTER Authenticate, because it reads users.locale and there is no
             * user to read before that. BEFORE ForcePasswordChange, so that a
             * user who is being forced to change their password still sees that
             * page in their own language.
             */
            ->authMiddleware([
                Authenticate::class,
                SetLocale::class,
                ForcePasswordChange::class,
            ])
            /*
             * Livewire updates — every table filter, every modal, every save on
             * this panel — arrive on Livewire's own route rather than on the
             * panel's, so route middleware does not run for them. Filament makes
             * Authenticate persistent for that reason; SetLocale needs the same
             * treatment or the first paint would be Arabic and every interaction
             * after it English.
             *
             * P1-T15, security review finding 5: this list held SetLocale alone.
             * The note that used to sit here treated persisting
             * ForcePasswordChange as the RISK — "would quietly change when that
             * guard fires" — when failing to persist it is what made the guard
             * skippable. The paragraph above was already correct; it simply was
             * not applied to the security middleware sitting beside it.
             *
             * ForcePasswordChange: an administrator resets someone's password
             * while that person has a panel page open. Every interaction on it —
             * filters, modals, saves — posts to Livewire's route, where the
             * guard did not run, so they kept working on a credential that had
             * already been revoked and replaced.
             *
             * AuthenticateSession: Filament does not persist this one either
             * (see FilamentServiceProvider::boot), so the session was not being
             * invalidated by the password-hash change it exists to detect.
             */
            ->persistentMiddleware([
                SetLocale::class,
                AuthenticateSession::class,
                ForcePasswordChange::class,
            ]);
    }
}
