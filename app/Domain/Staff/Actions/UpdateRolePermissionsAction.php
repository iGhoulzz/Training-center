<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\Role;
use App\Models\User;
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
        Gate::forUser($actor)->authorize('update', $role);

        $role->syncPermissions(array_values(array_unique($permissions)));
    }
}
