<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
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
 * This bypasses the ACTOR-RELATIVE guards (1, 2 and 4) by design — they cannot
 * mean anything without a principal to authorize. It does NOT bypass guard 3:
 * the last active super admin is a system invariant, and a seeder is no more
 * entitled to destroy it than a request is. syncRoles() below engages
 * SuperAdminInvariantService whenever it would actually remove the role.
 *
 * The class is named, namespaced, and documented so that its use in application
 * request code would be obviously wrong on sight, and is caught by the
 * architecture tests, which allow the raw Spatie writers ONLY here and in the
 * request-path Actions, and allow this class itself to be called only from
 * seeders, factories and console setup.
 *
 * DO NOT call this from a controller, a Filament page, a policy, a job that
 * runs on behalf of a user, or anywhere an authenticated actor exists. Use the
 * Actions there instead.
 */
final class SystemRoleWriter
{
    public function __construct(
        private readonly SuperAdminInvariantService $invariant,
    ) {}

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
     * Replace a user's entire role set, with no AUTHORIZATION — but guard 3
     * still applies.
     *
     * Guards 1, 2 and 4 are actor-relative and cannot mean anything without a
     * principal, so they are genuinely exempt here. Guard 3 is not: it is a
     * system invariant, and losing the last active super admin locks the
     * install out of role management permanently. A seeder is no more entitled
     * to cause that than a request is.
     *
     * The invariant is engaged only when this write actually removes the
     * super_admin role from a user who currently holds it. Bootstrapping a
     * fresh database — where no super admin exists yet — must not be blocked by
     * a rule about the *last* one.
     *
     * @param  array<int, string>  $roles
     */
    public function syncRoles(User $user, array $roles): void
    {
        $removesSuperAdmin = $user->isSuperAdmin()
            && ! in_array(Role::SUPER_ADMIN, $roles, true);

        if (! $removesSuperAdmin) {
            $user->syncRoles($roles);

            return;
        }

        $this->invariant->protect(fn () => $user->syncRoles($roles));
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
