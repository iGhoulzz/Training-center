<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Session integrity for the admin language switch's route (P4-T18a, review round).
 *
 * WHAT WAS MISSING. `POST account/locale` is a plain web route, so the middleware the
 * admin panel wraps around its own pages did not run for it: a session that an
 * administrator had invalidated by resetting the account's password (the containment
 * step this application supports) could still write `users.locale`, and every toggle
 * appends a row to the append-only audit log. This is the P1-T15 defect again, in a
 * second kind of route: Laravel's stock `web` group has no AuthenticateSession, it is
 * opt-in.
 *
 * WHY NOT AuthenticatePrivateFileSession, WHICH DOES THE SAME THING. That class is
 * the private-file groups' guard, and PrivateFileAccessTest pins exactly the groups
 * that declare it, so that a route added outside one cannot go unprotected. This route
 * is not a file and is not one of those groups; borrowing the class would either fail
 * that pin or loosen it. It gets a class of its own that behaves the same way.
 *
 * WHY NOT FILAMENT'S. Filament's AuthenticateSession redirects to
 * Filament::getLoginUrl(), which resolves through the panel that is currently serving
 * the request, and this is not a panel route. The destination is written out, as
 * AuthenticatePrivateFileSession writes it, so a refusal is a redirect and never a 500.
 *
 * WHAT IT DOES NOT DO. The parent returns early for a guest, and so does this: the
 * controller answers a guest with a 403, which does not tell an anonymous caller where
 * to log in.
 */
final class AuthenticateAccountSession extends AuthenticateSession
{
    /**
     * Where an invalidated session is sent: the admin panel's login, as a literal path.
     */
    protected function redirectTo($request): string
    {
        return '/admin/login';
    }
}
