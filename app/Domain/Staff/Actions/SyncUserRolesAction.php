<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
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
     */
    public function execute(User $actor, User $target, array $roles): void
    {
        $desired = array_values(array_unique($roles));
        $current = $target->roles()->pluck('name')->all();

        $adding = array_values(array_diff($desired, $current));
        $removing = array_values(array_diff($current, $desired));

        // A no-op change authorizes nothing and touches nothing — this is what
        // lets an unchanged self-save through.
        if ($adding === [] && $removing === []) {
            return;
        }

        Gate::forUser($actor)->authorize('update', $target);

        // Guard 2: nobody edits their own roles, regardless of rank. Returns
        // true (no-op) when the actor is not the target.
        Gate::forUser($actor)->authorize('modifyOwnRoles', $target);

        // Guards 4 and 1: authorize each role entering or leaving the set.
        foreach ([...$adding, ...$removing] as $role) {
            Gate::forUser($actor)->authorize('assignRole', [User::class, $role]);
        }

        /*
         * The write and its audit entry share ONE transaction.
         *
         * Attaching a role writes model_has_roles, which fires no Eloquent event
         * on User — the LogsActivity concern cannot see it, so this is one of the
         * few places an explicit entry is the only option. Recording it outside
         * the transaction would leave an audit row for a role change that rolled
         * back, which is the failure the whole buffering decision exists to avoid.
         *
         * The no-op return above means a repeated sync writes nothing at all: a
         * seeder run twice must not manufacture a second "roles changed" event.
         */
        DB::transaction(function () use ($target, $desired, $adding, $removing): void {
            // Guard 3: only a super-admin removal can shrink the population, so
            // only that write needs the locked, atomic invariant check.
            if (in_array(Role::SUPER_ADMIN, $removing, true)) {
                $this->invariant->protect(fn () => $target->syncRoles($desired));
            } else {
                $target->syncRoles($desired);
            }

            activity()
                ->performedOn($target)
                ->event('roles_changed')
                ->withProperties(['added' => $adding, 'removed' => $removing])
                ->log('roles_changed');
        });
    }
}
