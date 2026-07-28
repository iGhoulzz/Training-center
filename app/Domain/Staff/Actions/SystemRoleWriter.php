<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $requested = array_values(array_unique((array) $roles));

        // Read, decide and log under the lock, for the same reason the
        // request-path Actions do: a diff computed outside the transaction can
        // describe a change from a state that has already moved.
        DB::transaction(function () use ($user, $requested): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $current = $locked->roles()->pluck('name')->all();
            $adding = array_values(array_diff($requested, $current));

            if ($adding === []) {
                return;
            }

            $locked->assignRole($requested);

            self::recordSystemEvent($locked, 'roles_changed', ['added' => $adding, 'removed' => []]);
        });
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
        $desired = array_values(array_unique($roles));

        DB::transaction(function () use ($user, $desired): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $current = $locked->roles()->pluck('name')->all();

            $adding = array_values(array_diff($desired, $current));
            $removing = array_values(array_diff($current, $desired));

            if ($adding === [] && $removing === []) {
                return;
            }

            $removesSuperAdmin = $locked->isSuperAdmin()
                && ! in_array(Role::SUPER_ADMIN, $desired, true);

            if ($removesSuperAdmin) {
                $this->invariant->protect(fn () => $locked->syncRoles($desired));
            } else {
                $locked->syncRoles($desired);
            }

            self::recordSystemEvent($locked, 'roles_changed', ['added' => $adding, 'removed' => $removing]);
        });
    }

    /**
     * Pin a role's permission set with no authorization. This is how the seeder
     * establishes that super_admin holds every permission.
     *
     * @param  iterable<int, string|Permission>|Collection<int, Permission>  $permissions
     */
    public function syncRolePermissions(Role $role, iterable $permissions): void
    {
        $desired = collect($permissions)
            ->map(fn (Permission|string $permission): string => $permission instanceof Permission
                ? $permission->name
                : $permission)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($role, $permissions, $desired): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            $current = $locked->permissions()->pluck('name')->all();

            $adding = array_values(array_diff($desired, $current));
            $removing = array_values(array_diff($current, $desired));

            if ($adding === [] && $removing === []) {
                return;
            }

            $locked->syncPermissions($permissions);

            self::recordSystemEvent($locked, 'permissions_changed', [
                'added' => $adding,
                'removed' => $removing,
            ]);
        });
    }

    /**
     * Record a change made by the SYSTEM, with no causer.
     *
     * causedByAnonymous() is the whole point and is not a detail. Seeders and
     * console commands run with whatever session happens to exist — in a test, or
     * in `php artisan tinker` on a live box, that can be a real logged-in user —
     * and the package would otherwise attribute a seeder's rewrite of the
     * permission matrix to whoever was signed in. Naming a person for a change
     * they did not make is worse than naming nobody.
     *
     * The no-op guards in every caller above are what stop a repeatable seeder
     * run from producing a fresh event each time it confirms the same grants.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function recordSystemEvent(Model $subject, string $event, array $properties): void
    {
        activity()
            ->causedByAnonymous()
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
