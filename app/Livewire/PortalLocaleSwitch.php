<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Staff\Actions\UpdateOwnLocaleAction;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The student portal's language switch, drawn in the portal's topbar (P4-T42).
 *
 * A LIVEWIRE COMPONENT ON A PANEL PAGE, NOT A ROUTE. The admin switch posts to a plain web
 * route, and that route had to be given, one by one, the protections a panel page already
 * has (routes/web.php), and it refuses anyone who cannot enter the ADMIN panel. A student
 * is such a person, and the route stays that way. This component is rendered by a render
 * hook of the student panel (StudentPanelProvider), so its update requests run through the
 * panel's persistent middleware: a deactivated student is signed out, a session whose
 * password changed is ended, a student held on the password form is redirected there, and
 * SetLocale applies the stored language. Nothing is re-plumbed for the `student` guard.
 *
 * THE ACTOR IS NEVER AN INPUT. It is the student guard's signed-in user, read inside the
 * method. There is no public property that names a user and the only argument is the
 * language, so there is no way to point the call at another account. The write is
 * UpdateOwnLocaleAction's, which takes one user: the actor.
 *
 * WHAT THE METHOD REFUSES, IN ORDER, AND WRITES NOTHING FOR ANY OF THEM.
 * - Not signed in on the student guard, or an account the panel does not admit: a 403.
 *   That is `canAccessPanel()`, so a deactivated account, one without
 *   `access_student_portal` and one with no student record are all refused here as they
 *   are at the door.
 * - A language outside SetLocale::SUPPORTED: a validation error.
 * - More than ten changes a minute for the user: a 429. Every change appends an activity
 *   row, so the rate bounds how fast the append-only log can grow, as the admin route's
 *   throttle does. The key is this component's own, so it shares no budget with another
 *   surface.
 *
 * THEN IT RELOADS THE PAGE THE STUDENT IS ON (a full navigation, not a morph), because the
 * language also sets the document's direction and every string on the page.
 */
class PortalLocaleSwitch extends Component
{
    /** Changes a user may make in a minute. */
    private const ATTEMPTS_PER_MINUTE = 10;

    public function switchLocale(string $locale, UpdateOwnLocaleAction $action): void
    {
        $panel = Filament::getPanel('student');
        $actor = $panel->auth()->user();

        if (! $actor instanceof User || ! $actor->canAccessPanel($panel)) {
            abort(403);
        }

        Validator::make(['locale' => $locale], [
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ])->validate();

        $key = 'portal-locale:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            abort(429);
        }

        RateLimiter::hit($key, 60);

        $action->execute($actor, $locale);

        $this->redirect($this->currentPageUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('filament.portal.locale-switch');
    }

    /**
     * The portal page this component was rendered on, or the portal's home.
     *
     * Livewire's originalPath() is the path in the signed snapshot, so it cannot be
     * chosen by the caller; it is still only followed when it is inside the portal.
     */
    private function currentPageUrl(): string
    {
        $panel = Filament::getPanel('student');
        $prefix = trim($panel->getPath(), '/');
        $path = trim(Livewire::originalPath(), '/');

        return $path === $prefix || str_starts_with($path, $prefix.'/')
            ? url($path)
            : $panel->getUrl();
    }
}
