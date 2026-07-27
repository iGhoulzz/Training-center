<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

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

        Gate::forUser($actor)->authorize('update', $role);

        $desired = array_values(array_unique($permissions));
        $current = $role->permissions()->pluck('name')->all();

        $adding = array_values(array_diff($desired, $current));
        $removing = array_values(array_diff($current, $desired));

        /*
         * A sync that changes nothing records nothing. Re-running the seeder, or
         * re-saving an untouched permissions form, must not manufacture an audit
         * event — a log full of "changed" entries where nothing changed is a log
         * nobody reads.
         */
        if ($adding === [] && $removing === []) {
            return;
        }

        /*
         * One transaction for the write and its entry. role_has_permissions is a
         * pivot: syncPermissions() fires no Eloquent event on Role, so the
         * LogsActivity concern there sees role renames but never this. An entry
         * written outside the transaction would survive a rollback and claim a
         * permission change that never landed.
         */
        DB::transaction(function () use ($role, $desired, $adding, $removing): void {
            $role->syncPermissions($desired);

            activity()
                ->performedOn($role)
                ->event('permissions_changed')
                ->withProperties(['added' => $adding, 'removed' => $removing])
                ->log('permissions_changed');
        });
    }
}
