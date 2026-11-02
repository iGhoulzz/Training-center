<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reactivates a staff account (sets is_active = true) — the matching path for
 * DeactivateUserAction that Task 5's is_active toggle needs (P1-T05).
 *
 * The toggle is not model-bound, so both directions must resolve to an Action.
 * Activation is authorized through UserPolicy::update for the same reason
 * deactivation is: an admin must not be able to restore a super admin's access
 * (guard 1) and nobody without update_user may flip the column at all. A super
 * admin may reactivate another super admin: it only adds to the population, and
 * guard 5 (P4-T43) refuses deleting or deactivating one, not restoring one.
 *
 * The authorization is asked of the LOCKED row, in the one global lock order
 * (super_admin role, then the user), like DeleteUserAction and
 * DeactivateUserAction: it used to read the caller's instance, so a role grant
 * committing in the gap could send a now-super-admin target past the rank check.
 *
 * SuperAdminInvariantService is deliberately not engaged here. It exists to stop
 * the active-super-admin population from reaching zero; activation can only
 * increase it, so there is nothing for the invariant to protect against.
 */
final class ActivateUserAction
{
    public function execute(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            Role::lockSuperAdminRow();

            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            Gate::forUser($actor)->authorize('update', $lockedUser);

            if ($lockedUser->is_active) {
                return;
            }

            $lockedUser->update(['is_active' => true]);
        });
    }
}
