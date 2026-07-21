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
        return $this->mayMutate($authUser, 'create_role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $this->mayMutate($authUser, 'update_role', $role);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $this->mayMutate($authUser, 'delete_role', $role);
    }

    /**
     * Bulk deletion is refused outright, for everyone.
     *
     * Filament authorizes a DeleteBulkAction ONCE against deleteAny() and never
     * consults delete() for the individual selected records. That made bulk
     * delete a clean bypass of the super_admin protection in delete(): the role
     * could not be deleted on its own, but could be deleted as part of a
     * selection. A per-record rule cannot be expressed here — deleteAny()
     * receives no records — so the only safe answer is no.
     *
     * Roles are a handful of rows managed deliberately; there is no legitimate
     * need to remove several at once. Delete them individually, where delete()
     * applies the protection.
     */
    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $this->mayMutate($authUser, 'restore_role', $role);
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        return $this->mayMutate($authUser, 'force_delete_role', $role);
    }

    /** Refused for the same reason as deleteAny(). */
    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    /** Refused for the same reason as deleteAny(). */
    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    /**
     * Replicating the super_admin role would produce an unprotected clone
     * holding every permission but a different primary key — so it would not be
     * recognised as super_admin by any rank check, while conferring the same
     * power. That is an escalation path, so the anchor may not be copied.
     */
    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $this->mayMutate($authUser, 'replicate_role', $role);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $this->mayMutate($authUser, 'reorder_role');
    }

    /**
     * The single gate every role mutation passes through.
     *
     * Guard 4 (`assign_role`) applies to EVERY role mutation, not just the ones
     * that happen to be top of mind. An earlier fix added it to create() and
     * update() only, and a probe promptly deleted the `staff` role with
     * `delete_role` alone. Funnelling all mutations through one helper makes
     * that class of omission structurally impossible: a new mutation method
     * that forgets to call this is visibly different from its neighbours.
     *
     * @param  string  $ability  The Shield permission for this specific operation.
     * @param  Role|null  $role  Omitted for record-less operations (create, reorder).
     */
    private function mayMutate(AuthUser $authUser, string $ability, ?Role $role = null): bool
    {
        if (! $authUser->can($ability) || ! $authUser->can('assign_role')) {
            return false;
        }

        return $role === null || ! $this->isProtectedFrom($authUser, $role);
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
