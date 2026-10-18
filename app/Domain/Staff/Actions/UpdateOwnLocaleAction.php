<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sets the signed-in user's OWN language (users.locale), and nobody else's (P4-T18a).
 *
 * THE SIGNATURE IS THE BOUNDARY. Every other Action here takes an actor and a target
 * and asks the Gate whether the first may touch the second. This one takes ONE user,
 * the actor, because a person's language is theirs to choose and not a thing one
 * account does to another: there is no target argument to point at somebody else, so
 * there is no way to call it for another user, and no permission to lend. A caller
 * holding some other user cannot reach their locale through this class.
 *
 * WHAT IT REFUSES.
 * - A locale the application cannot render. The value is checked against
 *   SetLocale::SUPPORTED, the same list the middleware that applies it reads, and
 *   anything else throws before the row is touched. SetLocale also resets an unsupported
 *   stored value to English, but that is a safety net for a hand-edited row and not a
 *   reason to let one in.
 * - An inactive account. A deactivated user's session is not allowed to write anything
 *   (their panel access is already revoked; this keeps a stale session from changing
 *   the row behind it).
 *
 * THE AUDIT TRAIL IS THE MODEL'S. `locale` is one of User::auditedAttributes(), so the
 * change below is logged as an ordinary `updated` event with the old and the new value
 * and the actor as its causer. A save that changes nothing logs nothing
 * (dontLogEmptyChanges), so choosing the language one already has leaves no entry.
 */
final class UpdateOwnLocaleAction
{
    /**
     * @throws InvalidArgumentException when the locale is not one of SetLocale::SUPPORTED, or the actor is inactive.
     */
    public function execute(User $actor, string $locale): void
    {
        if (! in_array($locale, SetLocale::SUPPORTED, true)) {
            throw new InvalidArgumentException("The locale [{$locale}] is not one this application can render.");
        }

        if (! $actor->is_active) {
            throw new InvalidArgumentException('An inactive account cannot change its language.');
        }

        $actor->update(['locale' => $locale]);
    }
}
