<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Resource;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /*
     * THE PANEL'S COLOURS, AS THE DESIGN GIVES THEM (P4-T20c). The handoff README's panel
     * table and the prototype use Tailwind 3's hex shades: gray 950 for the page, 900 for
     * a surface, 50 for text, 400 and 500 for the muted and dim text; amber 500 for primary
     * with 400 on hover; and the 400 shade of green, blue and red for success, info and
     * danger. Filament's built-in Color constants are Tailwind 4's palettes, which are
     * OKLCH re-tunings of the same names (its amber is visibly more saturated), so none of
     * them is used. Every shade is registered, because Filament reaches for 50 to 950.
     *
     * Filament stores a hex as OKLCH to three decimals, so a shade can come back one or two
     * units of 255 off on a channel: seven of the amber ones do. ThemeShellTest compares the
     * stored value.
     */
    private const GRAY = [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827', 950 => '#030712'];

    private const AMBER = [50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#f59e0b', 600 => '#d97706', 700 => '#b45309', 800 => '#92400e', 900 => '#78350f', 950 => '#451a03'];

    private const GREEN = [50 => '#f0fdf4', 100 => '#dcfce7', 200 => '#bbf7d0', 300 => '#86efac', 400 => '#4ade80', 500 => '#22c55e', 600 => '#16a34a', 700 => '#15803d', 800 => '#166534', 900 => '#14532d', 950 => '#052e16'];

    private const BLUE = [50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554'];

    private const RED = [50 => '#fef2f2', 100 => '#fee2e2', 200 => '#fecaca', 300 => '#fca5a5', 400 => '#f87171', 500 => '#ef4444', 600 => '#dc2626', 700 => '#b91c1c', 800 => '#991b1b', 900 => '#7f1d1d', 950 => '#450a0a'];

    public function panel(Panel $panel): Panel
    {
        // Match the public layout's fallback in a checkout whose assets are not built yet.
        if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'))) {
            $panel->viteTheme('resources/css/filament/admin/theme.css');
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->darkMode(isForced: true)
            ->themeSwitcher(false)
            ->font('Instrument Sans', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): Htmlable => Vite::fonts('instrument-sans'))
            ->sidebarWidth('16.125rem')
            // 1440px, the prototype's main column. The class is defined in theme.css.
            ->maxContentWidth('tc-width-admin')
            // The brand is the centre's own (config/public_site.php), never app.name. The logo
            // is a view: the 28px circle and the short name the prototype draws.
            ->brandName(fn (): string => (string) config('public_site.brand.short_name'))
            ->brandLogo(fn (): Htmlable => new HtmlString(view('filament.admin.brand')->render()))
            ->brandLogoHeight('1.75rem')
            ->collapsibleNavigationGroups(false)
            ->colors([
                'primary' => self::AMBER,
                'gray' => self::GRAY,
                'success' => self::GREEN,
                'info' => self::BLUE,
                'warning' => self::AMBER,
                'danger' => self::RED,
            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                // The shell owns groups by destination, overriding each page/resource's getNavigationGroup().
                $groups = [];
                $panel = Filament::getCurrentOrDefaultPanel();

                foreach (array_merge($panel->getPages(), $panel->getResources()) as $destination) {
                    if (! is_subclass_of($destination, Page::class) && ! is_subclass_of($destination, Resource::class)) {
                        continue;
                    }

                    if (! $destination::shouldRegisterNavigation() || ! $destination::canAccess()) {
                        continue;
                    }

                    $group = match (true) {
                        $destination === Dashboard::class, $destination === EnrollAndCollect::class => 'front_desk',
                        $destination === ExportHistory::class,
                        str_starts_with($destination, 'App\\Domain\\Finance\\Filament\\Pages\\Reports\\') => 'reports',
                        str_starts_with($destination, 'App\\Domain\\Enrollment\\') => 'enrollment',
                        str_starts_with($destination, 'App\\Domain\\Finance\\') => 'finance',
                        str_starts_with($destination, 'App\\Domain\\Publications\\') => 'publications',
                        default => 'administration',
                    };

                    $groups[$group] = [...($groups[$group] ?? []), ...$destination::getNavigationItems()];
                }

                foreach (['front_desk', 'enrollment', 'finance', 'reports', 'administration', 'publications'] as $group) {
                    $items = $groups[$group] ?? [];
                    usort($items, fn (NavigationItem $first, NavigationItem $second): int => $first->getSort() <=> $second->getSort());

                    $builder->group(NavigationGroup::make()
                        ->label(fn (): string => __("navigation.{$group}"))
                        ->collapsible(false)
                        ->items($items));
                }

                return $builder;
            })
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
            ->discoverResources(
                in: app_path('Domain/Publications/Filament/Resources'),
                for: 'App\Domain\Publications\Filament\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(in: app_path('Domain/Finance/Filament/Pages'), for: 'App\Domain\Finance\Filament\Pages')
            ->discoverPages(in: app_path('Domain/Enrollment/Filament/Pages'), for: 'App\Domain\Enrollment\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            /*
             * THE BELL (P4-T22). A queued report PDF is delivered as a database notification
             * (GenerateReportPdfJob) and so is a finished spreadsheet export; without this there
             * was no bell and neither signed download link could be reached. The student portal
             * stays without it. PasswordChange renders no topbar, and with it no bell, so the
             * forced password change is not reopened (ReportPdfNotificationTest proves both).
             *
             * NO POLLING, ON PURPOSE. Spec §4 gives sessions an 8-hour idle timeout, which is
             * SESSION_LIFETIME on the database driver, and every Livewire poll is a request that
             * refreshes the session's last_activity: Filament's default 30-second poll would keep
             * a tab left open on a front-desk PC signed in for ever, background tabs included.
             * No other admin component polls: the report chart widgets set $pollingInterval to
             * null. The bell reads the table on each page load, which is when spec §8's
             * "notified in-app when the file is ready" is delivered.
             */
            ->databaseNotifications()
            ->databaseNotificationsPolling(null)
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
