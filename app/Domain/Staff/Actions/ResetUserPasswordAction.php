<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        /*
         * The write and its audit entry share one transaction, so a reset that
         * rolls back leaves no record claiming it happened.
         */
        DB::transaction(function () use ($actor, $target, $plain): void {
            $target->forceFill([
                'password' => Hash::make($plain),
                'must_change_password' => true,
            ])->save();

            /*
             * RECORDED EXPLICITLY, BECAUSE THE DIFF CANNOT CARRY IT.
             *
             * `password` is absent from User::auditedAttributes() on purpose — a
             * hash in an audit table is a credential sitting where several people
             * can read it. But excluding it is exactly what would make this event
             * disappear: the save above moves `password` and, when the account was
             * already flagged, nothing else. dontLogEmptyChanges() then suppresses
             * the entry entirely, and a password reset — one of the events an audit
             * trail exists for — leaves no trace at all.
             *
             * So the fact is recorded as its own event. No password, no hash, not
             * even a length: the audit answer is WHO reset WHOSE credentials and
             * WHEN, and the secret itself is never part of that answer.
             */
            activity()
                ->causedBy($actor)
                ->performedOn($target)
                ->event(ActivityEvent::PASSWORD_RESET)
                ->log(ActivityEvent::PASSWORD_RESET);
        });

        return $plain;
    }
}
