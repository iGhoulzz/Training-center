<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Reactivates a staff account (sets is_active = true) — the matching path for
 * DeactivateUserAction that Task 5's is_active toggle needs (P1-T05).
 *
 * The toggle is not model-bound, so both directions must resolve to an Action.
 * Activation is authorized through UserPolicy::update for the same reason
 * deactivation is: an admin must not be able to restore a super admin's access
 * (guard 1), and nobody without update_user may flip the column at all.
 *
 * SuperAdminInvariantService is deliberately not engaged here. It exists to stop
 * the active-super-admin population from reaching zero; activation can only
 * increase it, so there is nothing for the invariant to protect against.
 */
final class ActivateUserAction
{
    public function execute(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('update', $target);

        if ($target->is_active) {
            return;
        }

        $target->update(['is_active' => true]);
    }
}
