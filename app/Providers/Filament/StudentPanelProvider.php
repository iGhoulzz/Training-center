<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\PasswordChange;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\LogOutInactiveUser;
use App\Http\Middleware\SetGuestPanelLocale;
use App\Http\Middleware\SetLocale;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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

/**
 * The student portal (P3-T01).
 *
 * A SECOND PANEL, NOT A SECOND AUTH IMPLEMENTATION.
 * -------------------------------------------------
 * System design §3's table once called /portal "Blade + Tailwind" while the prose
 * directly below it said "separate panels with separate auth guards". Both could
 * not be literal; phase 3's design resolved it in favour of a panel, and P3-T14
 * corrected the table to say so. The reasoning is kept because §4
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
 * THE FOUR PORTAL PAGES ARRIVE IN T7, THE SOLE WRITER OF THIS PAGE-DISCOVERY
 * SEAM.
 * -----------------------------------------------------------------------
 * Two calls below, one per owning domain — Overview and MyEnrollments live
 * under Enrollment, MyBalance under Finance — mirroring exactly how
 * AdminPanelProvider splits discoverResources() per domain rather than
 * pointing one call at a shared parent directory. A grep for the discovery
 * method's literal name against this file must find exactly those two calls
 * and nothing else, which is why this docblock never spells the method name
 * out — see the two calls themselves, just below the password-page note.
 *
 * The password page stays registered by class name, not discovered: see its
 * own docblock for why (SimplePage's layout, ForcePasswordChange's route
 * exemption).
 */
class StudentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'))) {
            $panel->viteTheme('resources/css/filament/student/theme.css');
        }

        return $panel
            // NOT ->default(). The admin panel is the default; making this one
            // default would send unqualified Filament routing to the portal.
            ->id('student')
            ->path('portal')
            ->authGuard('student')
            // The same sign-in page as the admin panel (P4-T33): the wrong-door message
            // matters most here, where a staff member who types their password gets
            // told it is wrong, and the reason an open session ended is shown on it.
            ->login(Login::class)
            ->darkMode(false)
            ->themeSwitcher(false)
            ->font(fn (): ?string => app()->getLocale() === 'ar' ? 'IBM Plex Sans Arabic' : null, provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): Htmlable => (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
                ? Vite::fonts(['instrument-serif', 'ibm-plex-sans-arabic'])
                : new HtmlString(''))
            // A closure, not a bare __() call: the panel is registered at boot,
            // before SetLocale has read the actor's locale, so resolving the
            // string eagerly would pin it to the fallback for every request.
            ->brandName(fn (): string => __('portal.brand'))
            ->brandLogo(fn (): Htmlable => new HtmlString(view('filament.portal.brand')->render()))
            ->brandLogoHeight('2.75rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            /*
             * The password page is REGISTERED, not discovered.
             *
             * It lives at app/Filament/Pages/PasswordChange.php, which this panel
             * does not scan — and discovery would not find it anyway: the
             * discovery calls below filter on Page::class, while that page sets
             * $layout to the simple layout so it renders no panel chrome. The
             * admin panel registers it by class name for the same reason.
             *
             * It has to be here. A student issued a temporary password is held by
             * ForcePasswordChange until they change it, and without this page on
             * this panel that containment would have nowhere to send them.
             */
            ->discoverPages(
                in: app_path('Domain/Enrollment/Filament/Portal/Pages'),
                for: 'App\Domain\Enrollment\Filament\Portal\Pages',
            )
            ->discoverPages(
                in: app_path('Domain/Finance/Filament/Portal/Pages'),
                for: 'App\Domain\Finance\Filament\Portal\Pages',
            )
            ->pages([
                PasswordChange::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // The login has no auth middleware; persist only this addition for its Livewire calls.
                SetGuestPanelLocale::class,
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
             *
             * LogOutInactiveUser goes first, for the admin panel's reason (P4-T33): a
             * deactivated student is signed out and sent to /portal/login with the
             * reason, instead of meeting Authenticate's 403.
             */
            ->authMiddleware([
                LogOutInactiveUser::class,
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
             *
             * THIS LIST IS REGISTERED GLOBALLY, NOT PER PANEL (P3-T01).
             * Panel::persistentMiddleware() ends in
             * Livewire::addPersistentMiddleware() — HasMiddleware.php:126 — which
             * is one application-wide list. So every class named here that the
             * admin panel also names is, today, redundant: removing
             * AuthenticateSession from this list alone changes nothing, and a
             * mutation probe that removes it from only one panel cannot fail.
             *
             * It is still declared, for two reasons. It states what this panel
             * requires rather than what it happens to inherit, so the admin panel
             * dropping an entry cannot silently disarm the portal. And Filament
             * itself globally persists only Authenticate — AuthenticateSession
             * and ForcePasswordChange are this application's additions, so
             * nothing outside these two providers keeps them persistent.
             *
             * LivewirePersistentGuardTest's portal cases are honest about the
             * consequence: they prove the guards are persistent, and they assert
             * DIRECTLY that this panel's guard gets its own session slot —
             * password_hash_student present, password_hash_web absent. That
             * assertion exists because the logout behaviour alone did not prove
             * it: a consistently wrong slot goes stale and logs out identically.
             *
             * They cannot prove this particular declaration is load-bearing while
             * the admin panel names the same classes.
             *
             * LogOutInactiveUser is listed on the same terms (P4-T33): a deactivated
             * student with a portal page open is signed out on the next Livewire
             * request, not left driving the page until a full reload.
             */
            ->persistentMiddleware([
                SetGuestPanelLocale::class,
                LogOutInactiveUser::class,
                SetLocale::class,
                AuthenticateSession::class,
                ForcePasswordChange::class,
            ]);
    }
}
