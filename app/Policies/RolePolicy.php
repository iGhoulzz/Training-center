<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization for the Role resource.
 *
 * This policy is the binding request-path boundary for role mutation now that
 * the Role model holds no write guards (P1-T04c). Two protections beyond the
 * plain Shield-generated permission checks:
 *
 *   - The canonical super_admin role cannot be edited, deleted, or force
 *     deleted by anyone. Renaming it, deleting it, or reducing its permissions
 *     would defeat every rank check that resolves against it, so those controls
 *     are refused here and the app-owned Shield role pages route their writes
 *     through UpdateRolePermissionsAction, which honours this policy.
 *   - Nobody may edit or delete a role they themselves hold. Otherwise an actor
 *     could escalate by adding a permission to a role they carry.
 *
 * The role-CRUD permissions (update_role, delete_role, …) are seeded only to
 * super_admin, so ordinary role management is already super-admin-only; these
 * two rules close the remaining self-escalation and anchor-tampering paths.
 */
class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('view_role');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        if (! $authUser->can('update_role')) {
            return false;
        }

        return ! $this->isProtectedFrom($authUser, $role);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        if (! $authUser->can('delete_role')) {
            return false;
        }

        return ! $this->isProtectedFrom($authUser, $role);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_role');
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('restore_role');
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        if (! $authUser->can('force_delete_role')) {
            return false;
        }

        return ! $this->isProtectedFrom($authUser, $role);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_role');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_role');
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('replicate_role');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_role');
    }

    /**
     * A role is off-limits for mutation when it is the canonical super_admin
     * anchor, or when the acting user currently holds it.
     *
     * The held-role check reads the actor's role pivot fresh by primary key —
     * the same immutable-id identity resolution the rest of the system uses,
     * never a mutable role name.
     */
    private function isProtectedFrom(AuthUser $authUser, Role $role): bool
    {
        if ($role->isSuperAdmin()) {
            return true;
        }

        return $authUser instanceof User
            && $authUser->roles()->whereKey($role->getKey())->exists();
    }
}
