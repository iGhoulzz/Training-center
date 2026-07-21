<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

        $role->syncPermissions(array_values(array_unique($permissions)));
    }
}
