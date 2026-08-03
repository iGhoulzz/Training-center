<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Support\ActivityEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single sanctioned path for changing a role's permission set from the
 * request path. The app-owned Shield role pages route their writes here rather
 * than calling Role::syncPermissions() directly.
 *
 * Authorization is RolePolicy::update, which denies:
 *   - the canonical super_admin role (its permission set is fixed at system
 *     setup and may never be weakened through the UI — this is what closes the
 *     "Shield editor silently strips super_admin permissions" bypass), and
 *   - any role the acting user currently holds (so nobody edits the permissions
 *     of a role that would escalate themselves).
 *
 * The trusted, actorless counterpart used by the seeder is
 * SystemRoleWriter::syncRolePermissions().
 */
final class UpdateRolePermissionsAction
{
    /**
     * @param  array<int, string>  $permissions  Permission names the role should hold, exactly.
     */
    public function execute(User $actor, Role $role, array $permissions): void
    {
        // Guard 4 is checked explicitly rather than left to the policy alone.
        // RolePolicy::update() also requires assign_role, but stating it here
        // keeps the Action's contract self-evident: changing what a role can do
        // is a role-management operation, not an incidental record edit.
        if (! $actor->can('assign_role')) {
            throw new AuthorizationException(__('staff.escalation.requires_assign_role'));
        }

        $desired = array_values(array_unique($permissions));

        /*
         * READ, DECIDE, WRITE AND LOG UNDER ONE LOCK.
         *
         * Computing the diff before the transaction let two concurrent syncs read
         * the same "current" set and each record an added/removed list against a
         * state that had already moved. The permissions would end up right and the
         * audit trail would describe a change that never happened that way.
         */
        DB::transaction(function () use ($actor, $role, $desired): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            /*
             * AUTHORIZED AGAINST THE LOCKED ROW, INSIDE THE TRANSACTION.
             *
             * RolePolicy::update() refuses a role the ACTOR HOLDS — nobody edits
             * the permissions of a role they are a member of. That question was
             * previously asked before the lock, against the passed instance, and
             * the answer could go stale in the gap: an actor granted the role
             * between the check and the write kept an authorization that was no
             * longer true, and the permission change landed anyway.
             *
             * Asking it here means the decision and the write are serialized by
             * the same lock. Confirmed by regression test: granting the actor the
             * role mid-transaction now refuses.
             */
            Gate::forUser($actor)->authorize('update', $locked);

            $current = $locked->permissions()->pluck('name')->all();

            $adding = array_values(array_diff($desired, $current));
            $removing = array_values(array_diff($current, $desired));

            /*
             * A sync that changes nothing records nothing. Re-running the seeder,
             * or re-saving an untouched permissions form, must not manufacture an
             * audit event — a log full of "changed" entries where nothing changed
             * is a log nobody reads.
             */
            if ($adding === [] && $removing === []) {
                return;
            }

            $locked->syncPermissions($desired);

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->event(ActivityEvent::PERMISSIONS_CHANGED)
                ->withProperties(['added' => $adding, 'removed' => $removing])
                ->log(ActivityEvent::PERMISSIONS_CHANGED);
        });
    }
}
