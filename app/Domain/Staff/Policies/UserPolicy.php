<?php

declare(strict_types=1);

namespace App\Domain\Staff\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for staff account management, including the three escalation
 * guards from section 5 of the design spec:
 *
 *   1. An admin cannot create, edit, or delete a super admin.
 *   2. No user can modify their own roles or permissions.
 *   3. The last active super admin cannot be deleted or deactivated.
 *
 * Guard 3 is NOT enforced here. A policy only covers code paths that ask it,
 * so it would be bypassed by seeders, tinker, queued jobs and bulk actions.
 * It lives on the User model instead — see User::assertNotLastSuperAdmin().
 *
 * DELIBERATE EXCEPTION TO THE PERMISSION-ONLY RULE
 * ------------------------------------------------
 * The project's first non-negotiable is that authorization is permission-based,
 * never role-based: $user->can('delete_user'), never $user->hasRole('admin').
 *
 * outranks() and assignRole() below break that rule on purpose, and this is the
 * only place in the codebase where they should. What they express is *rank* —
 * "is this person a super admin" — which is not reducible to a permission
 * check. Encoding it as a permission would mean inventing a synthetic
 * `is_super_admin` ability that duplicates the role and can be granted
 * directly with givePermissionTo(), which is precisely the escalation these
 * guards exist to prevent. The role is the boundary, so the role is checked.
 *
 * Rank is resolved through User::isSuperAdmin(), which compares the super-admin
 * role's immutable primary key against the account's live role pivot — never a
 * mutable, possibly-stale role name. See App\Models\Role for why identity is
 * keyed on the id and why the name/row are frozen.
 *
 * A reviewer applying the permission-only rule mechanically should read this as
 * intentional, not an oversight. Every other check in this class is
 * permission-based.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_user');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('view_user');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_user');
    }

    public function update(User $actor, User $target): bool
    {
        if (! $actor->can('update_user')) {
            return false;
        }

        return $this->outranks($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        if (! $actor->can('delete_user')) {
            return false;
        }

        // Guard 2: no self-deletion.
        if ($actor->is($target)) {
            return false;
        }

        return $this->outranks($actor, $target);
    }

    /**
     * Guard 1: only a super admin may grant the super_admin role.
     */
    public function assignRole(User $actor, string $role): bool
    {
        if (! $actor->can('assign_role')) {
            return false;
        }

        return $role !== Role::SUPER_ADMIN || $actor->isSuperAdmin();
    }

    /**
     * Guard 2: nobody edits their own role assignments, regardless of rank.
     */
    public function modifyOwnRoles(User $actor, User $target): bool
    {
        return ! $actor->is($target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        if (! $actor->can('reset_user_password')) {
            return false;
        }

        return $this->outranks($actor, $target);
    }

    /**
     * Guard 1: a non-super-admin may never act on a super admin.
     *
     * Note this tests the target's *role*, not their permissions. A user holding
     * delete_user directly via givePermissionTo() still cannot reach a super
     * admin, and a user holding both admin and super_admin is treated as a
     * super admin in both directions.
     */
    private function outranks(User $actor, User $target): bool
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return false;
        }

        return true;
    }
}
