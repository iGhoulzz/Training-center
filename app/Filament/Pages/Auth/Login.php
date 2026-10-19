<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\LogOutInactiveUser;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in page of both panels (P4-T33).
 *
 * TWO THINGS DIFFER FROM FILAMENT'S PAGE, AND NOTHING ELSE DOES.
 * ---------------------------------------------------------------
 * 1. A CLEARER REFUSAL WHEN THE PASSWORD WAS RIGHT AND THE PANEL IS NOT YOURS.
 *
 *    Filament answers "These credentials do not match our records" to a correct
 *    email and password that canAccessPanel() refuses: a student account at
 *    /admin, a staff account at /portal, an account holding no access permission,
 *    a deactivated account. Someone who typed the right password for the wrong
 *    door is told the password is wrong, tries it again, and phones the office.
 *    That case now says `auth.panel_access_denied`.
 *
 *    A WRONG PASSWORD KEEPS THE OLD MESSAGE, EXACTLY. The new one is only ever
 *    raised after the credentials have been validated, so it tells nobody
 *    anything the password did not already prove: an unknown email, a known email
 *    with a wrong password and a stranger guessing all still read the same, and
 *    the page cannot be used to learn which addresses have accounts. The flag
 *    below is set by the one place that knows the credentials were good.
 *
 *    NOTHING NEEDS LOGGING OUT. Filament's authenticate() asks canAccessPanel()
 *    BEFORE it signs anyone in, inside the same Timebox that pads a wrong password
 *    to the same duration, so a refused account never holds a session on either
 *    guard. Only the message differs. The Attempting and Failed events fire exactly
 *    as before, so AppServiceProvider's login_failed activity entry (the attempted
 *    email, no causer) is still written for this case, and the rate limit of five
 *    attempts is the parent's, untouched.
 *
 * 2. THE REASON AN OPEN SESSION WAS ENDED.
 *
 *    LogOutInactiveUser redirects here with ?reason=deactivated. The message could
 *    instead have been flashed into the session, but Livewire's client does not
 *    stop at the redirect: fetch() follows it on its own, rendering this page once
 *    for nobody, and the notification is pulled out of the session by that render.
 *    The navigation that follows finds nothing. The URL survives both requests, so
 *    the reason travels there and the notification is raised when the page mounts.
 *    Only the one value the middleware sends does anything.
 */
class Login extends FilamentLogin
{
    /**
     * Set once the credentials have been validated and the panel still refused the
     * account. Never set for a wrong password, which is the point.
     */
    protected bool $credentialsWereCorrectButPanelRefused = false;

    public function mount(): void
    {
        parent::mount();

        // Only for somebody signed out. parent::mount() sends a signed-in visitor on
        // to the panel, and the toast would travel with them: an active admin who
        // followed a crafted link would be told, on their own dashboard, that they
        // had been deactivated.
        if (request()->query(LogOutInactiveUser::REASON_QUERY) === LogOutInactiveUser::REASON_DEACTIVATED
            && ! Filament::auth()->check()) {
            Notification::make()
                ->title(__('auth.session_ended_inactive'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Filament calls this only after the email and password have been validated,
     * which is what makes a refusal here a "right password, wrong door" refusal.
     */
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        $allowed = parent::isUserAllowedToAccessPanel($user);

        if (! $allowed) {
            $this->credentialsWereCorrectButPanelRefused = true;
        }

        return $allowed;
    }

    protected function throwFailureValidationException(): never
    {
        if (! $this->credentialsWereCorrectButPanelRefused) {
            parent::throwFailureValidationException();
        }

        throw ValidationException::withMessages([
            'data.email' => __('auth.panel_access_denied'),
        ]);
    }
}
