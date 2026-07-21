<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;

/**
 * TRUSTED SYSTEM-SETUP PATH — NO ACTOR, NO AUTHORIZATION.
 * ======================================================
 * This class exists so that the actorless writes seeders, factories, and
 * console setup legitimately need cannot be confused with the request-path
 * Actions. The request-path Actions (SyncUserRolesAction,
 * UpdateRolePermissionsAction, …) all take a `User $actor` and authorize
 * through the gate; there is no way to call them without an actor. When there
 * IS no principal — provisioning a fresh install, building a fixture — those
 * Actions are the wrong tool, and this is the right one.
 *
 * Everything here bypasses every escalation guard by design. It is named,
 * namespaced, and documented so that its use in application request code would
 * be obviously wrong on sight and is caught by the architecture tests, which
 * allow the raw Spatie writers ONLY here and in the request-path Actions.
 *
 * DO NOT call this from a controller, a Filament page, a policy, a job that
 * runs on behalf of a user, or anywhere an authenticated actor exists. Use the
 * Actions there instead.
 */
final class SystemRoleWriter
{
    /**
     * Assign roles to a user with no authorization. For seeders and factories.
     *
     * @param  string|array<int, string>  $roles
     */
    public function assignRoles(User $user, string|array $roles): void
    {
        $user->assignRole($roles);
    }

    /**
     * Replace a user's entire role set with no authorization.
     *
     * @param  array<int, string>  $roles
     */
    public function syncRoles(User $user, array $roles): void
    {
        $user->syncRoles($roles);
    }

    /**
     * Pin a role's permission set with no authorization. This is how the seeder
     * establishes that super_admin holds every permission.
     *
     * @param  iterable<int, string|Permission>|Collection<int, Permission>  $permissions
     */
    public function syncRolePermissions(Role $role, iterable $permissions): void
    {
        $role->syncPermissions($permissions);
    }
}
