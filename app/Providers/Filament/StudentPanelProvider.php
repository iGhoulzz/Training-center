<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\PasswordChange;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The student portal (P3-T01).
 *
 * A SECOND PANEL, NOT A SECOND AUTH IMPLEMENTATION.
 * -------------------------------------------------
 * System design §3's table called /portal "Blade + Tailwind" while the prose
 * directly below it said "separate panels with separate auth guards". Both could
 * not be literal; phase 3's design resolved it in favour of a panel, because §4
 * had already refused a second auth package to avoid two competing sources of
 * truth, and hand-rolling login, throttling, session invalidation and the
 * forced-password gate would have reintroduced exactly that on the system's most
 * sensitive new surface.
 *
 * Filament's own login page self-throttles at rateLimit(5), which is why §4's
 * named `login` limiter does NOT return in this phase. The named limiter phase 3
 * does introduce is `certificate-verification`, on the public verifier (T8),
 * where it is referenced by real routes — the property whose absence got
 * P1-T03's login limiter deleted.
 *
 * SEPARATE GUARD, SHARED SESSION COOKIE.
 * The two guards hold separate authentication state (login_student_… beside
 * login_web_…) inside one Laravel session. They are not separate browser
 * sessions: Filament's LogoutController calls session()->invalidate(), which
 * flushes the whole session, so logging out here also ends an /admin login in
 * the same browser. That is accepted rather than worked around — it errs toward
 * logging out too much — and PortalLogoutTest pins it so nobody later "fixes" it
 * into a per-guard logout.
 *
 * NO PAGES YET, DELIBERATELY.
 * The panel ships with login and the password page. The four portal pages arrive
 * in T7, which is the only other task that touches this file and the sole writer
 * of its discoverPages() seam.
 */
class StudentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // NOT ->default(). The admin panel is the default; making this one
            // default would send unqualified Filament routing to the portal.
            ->id('student')
            ->path('portal')
            ->authGuard('student')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            /*
             * The password page is REGISTERED, not discovered.
             *
             * It lives at app/Filament/Pages/PasswordChange.php, which this panel
             * does not scan — and discovery would not find it anyway:
             * discoverPages() filters on Page::class, while that page sets
             * $layout to the simple layout so it renders no panel chrome. The
             * admin panel registers it by class name for the same reason.
             *
             * It has to be here. A student issued a temporary password is held by
             * ForcePasswordChange until they change it, and without this page on
             * this panel that containment would have nowhere to send them.
             */
            ->pages([
                PasswordChange::class,
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
            /*
             * Same order and same reasoning as the admin panel: SetLocale after
             * Authenticate because it reads users.locale and there is no user to
             * read before that, and before ForcePasswordChange so a user being
             * forced to change their password still sees that page in their own
             * language.
             */
            ->authMiddleware([
                Authenticate::class,
                SetLocale::class,
                ForcePasswordChange::class,
            ])
            /*
             * PERSISTENT, for the reason P1-T15's security review established on
             * the admin panel (finding 5): Livewire updates arrive on Livewire's
             * own route rather than this panel's, so route middleware does not run
             * for them. A guard that does not run there is skippable — a flagged
             * user could keep driving every other component on the panel while
             * only page loads were contained.
             *
             * The portal inherits that reasoning rather than rediscovering it.
             */
            ->persistentMiddleware([
                SetLocale::class,
                AuthenticateSession::class,
                ForcePasswordChange::class,
            ]);
    }
}
