<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Domain\Staff\Support\RoleSet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single sanctioned path for setting a staff account's roles from the
 * request path (Task 5's UserResource calls this).
 *
 * It receives the acting user explicitly and authorizes every escalation guard
 * through the gate, then performs the write — routing a super-admin removal
 * through SuperAdminInvariantService so the last-super-admin invariant holds
 * atomically. It never assigns roles without an actor; the trusted, actorless
 * counterpart for seeders and factories is SystemRoleWriter.
 *
 * Guards enforced (all via Gate::forUser($actor)):
 *   - update          : the actor may administer this target account, and a
 *                       non-super-admin may not touch a super admin (guard 1,
 *                       target side).
 *   - modifyOwnRoles  : nobody edits their own roles (guard 2).
 *   - assignRole      : every changed role requires the assign_role ability
 *                       (guard 4), and super_admin may be granted or revoked
 *                       only by a super admin (guard 1, role side).
 *   - invariant       : removing super_admin from the last active super admin
 *                       is refused by SuperAdminInvariantService (guard 3, 422).
 */
final class SyncUserRolesAction
{
    public function __construct(
        private readonly SuperAdminInvariantService $invariant,
    ) {}

    /**
     * @param  array<int, string>  $roles  Role names the account should hold, exactly.
     *                                     Resolved to rows; spelling is not trusted.
     */
    public function execute(User $actor, User $target, array $roles): void
    {
        /*
         * RESOLVED TO ROWS BEFORE ANYTHING ELSE HAPPENS (P1-T15, finding 2).
         *
         * The submitted names are untrusted strings and the database matches
         * them case-insensitively. Resolving here means every guard below
         * compares primary keys against the same rows Spatie will attach, and
         * an unknown name is refused before it reaches a guard at all.
         */
        $desired = RoleSet::resolve($roles);

        /*
         * EVERYTHING HAPPENS UNDER THE LOCK: READ, DECIDE, AUTHORIZE, WRITE, LOG.
         *
         * The diff used to be computed before the transaction opened. Two
         * concurrent syncs would then both read the same "current" set, both
         * compute an added/removed list against it, and both write an audit entry
         * describing a change from a state that no longer existed by the time they
         * committed. The rows would be right and the audit trail would be wrong —
         * which is the worse of the two failures, because it is the one nobody
         * notices.
         *
         * The target row is locked first so the pivot read below is serialized
         * against any other sync for the same account.
         */
        DB::transaction(function () use ($actor, $target, $desired): void {
            /*
             * THE SUPER-ADMIN ROLE ROW IS LOCKED BEFORE THE USER ROW. ALWAYS.
             *
             * ONE GLOBAL ORDER: super_admin role -> user. DeleteUserAction and
             * DeactivateUserAction reach the invariant service first, which locks
             * that role row and only then touches the account. This Action used to
             * take the user lock first and the role lock later (through the
             * invariant), so a role sync and a deactivation against the same super
             * admin could each hold what the other was waiting for.
             *
             * Taken unconditionally rather than only when super_admin is involved,
             * because whether it IS involved cannot be known until the current
             * roles are read — and that read is what the user lock protects. The
             * cost is that role writes serialize against each other globally,
             * which is acceptable for a rare administrative operation and is the
             * same row the invariant already serializes every reducer on.
             */
            Role::lockSuperAdminRow();

            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            $current = RoleSet::heldBy($locked);

            $adding = $desired->diff($current);
            $removing = $current->diff($desired);

            // A no-op change authorizes nothing and touches nothing — this is
            // what lets an unchanged self-save through.
            if ($adding->isEmpty() && $removing->isEmpty()) {
                return;
            }

            Gate::forUser($actor)->authorize('update', $locked);

            // Guard 2: nobody edits their own roles, regardless of rank. Returns
            // true (no-op) when the actor is not the target.
            Gate::forUser($actor)->authorize('modifyOwnRoles', $locked);

            // Guards 4 and 1: authorize each role entering or leaving the set.
            foreach ([...$adding->all(), ...$removing->all()] as $role) {
                Gate::forUser($actor)->authorize('assignRole', [User::class, $role]);
            }

            // Guard 3: only a super-admin removal can shrink the population, so
            // only that write needs the locked, atomic invariant check.
            if ($removing->containsSuperAdmin()) {
                $this->invariant->protect(fn () => $locked->syncRoles($desired->names()));
            } else {
                $locked->syncRoles($desired->names());
            }

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('roles_changed')
                ->withProperties(['added' => $adding->names(), 'removed' => $removing->names()])
                ->log('roles_changed');
        });
    }
}
