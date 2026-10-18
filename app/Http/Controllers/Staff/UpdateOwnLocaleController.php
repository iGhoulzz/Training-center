<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Actions\UpdateOwnLocaleAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin panel's language switch posts here (P4-T18a).
 *
 * THE ONLY THING IT CAN DO IS SET THE SIGNED-IN USER'S OWN LANGUAGE. The user is read
 * from the session, never from the request, so there is no field to name somebody else
 * in, and the write is UpdateOwnLocaleAction's: this controller authenticates, validates
 * the one input and redirects.
 *
 * A GUEST GETS A 403 AND NO WRITE, the way the private-file controllers refuse one: a
 * route like this tells an anonymous caller nothing about where to log in. The route sits
 * in the `web` group, so the session, the CSRF check and cookie handling are all on; a
 * POST with no token is refused (419) before this runs. An inactive account is refused
 * too.
 *
 * `locale` must be one of SetLocale::SUPPORTED. The Action checks the same list again,
 * because the controller is not the only way into it.
 */
final class UpdateOwnLocaleController extends Controller
{
    public function __invoke(Request $request, UpdateOwnLocaleAction $action): RedirectResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->is_active) {
            abort(403);
        }

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ]);

        $action->execute($actor, $validated['locale']);

        // Back to the page the switch was on; the panel itself when there is none.
        return redirect()->back(fallback: url('/admin'));
    }
}
