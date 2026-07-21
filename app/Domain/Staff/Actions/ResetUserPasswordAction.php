<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues a temporary password for a staff account (P1-T05).
 *
 * Takes the actor and authorizes, like every other request-path Action.
 * Resetting a password is an account-takeover primitive: without this an admin
 * could reset a super admin's password and simply log in as them, defeating
 * every rank guard in the system. UserPolicy::resetPassword() already refuses
 * that; calling it here is what makes it binding.
 *
 * This is the single password-issuing flow. UserResource's create page calls it
 * too, so a newly created account and a reset account receive their credential
 * the same way rather than through a second, divergent implementation.
 */
final class ResetUserPasswordAction
{
    /**
     * Generate a temporary password, store its hash, and force a change at next
     * login.
     *
     * @return string The plaintext password, shown once to the administrator.
     */
    public function execute(User $actor, User $target): string
    {
        Gate::forUser($actor)->authorize('resetPassword', $target);

        $plain = Str::password(16, symbols: false);

        $target->forceFill([
            'password' => Hash::make($plain),
            'must_change_password' => true,
        ])->save();

        return $plain;
    }
}
