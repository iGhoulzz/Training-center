<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

use App\Models\Role;

/**
 * The super-admin role's primary key, read once per request or queued job.
 *
 * WHAT IS MEMOIZED, AND WHAT DELIBERATELY IS NOT
 * ----------------------------------------------
 * Only the ROLE ID. The role id is not the authorization read.
 *
 * `User::isSuperAdmin()` is two statements: resolve the super-admin role's key,
 * then ask the pivot whether this user holds it. Its docblock records why the
 * second must stay fresh — "reading a stale in-memory roles relation let the
 * last super admin be deleted when that relation happened to be empty". That
 * read is the authorization decision and this class never touches it.
 *
 * The first statement asks a different question: which row is the canonical
 * super-admin role? That row is protected by RolePolicy, by
 * UpdateRolePermissionsAction, and by the (name, guard_name) unique index. Its
 * id cannot change under a running request, so re-reading it 28 times on a
 * 10-row user page — as P3-T15's audit measured — buys nothing.
 *
 * WHY SCOPED AND NOT A SINGLETON, OR A STATIC PROPERTY
 * ----------------------------------------------------
 * `AppServiceProvider::register()` binds this with `$this->app->scoped()`,
 * which Laravel rebuilds per request AND between queued jobs:
 * `QueueServiceProvider.php:263` calls `$app->forgetScopedInstances()` in the
 * worker's reset callback, verified against the installed framework rather than
 * assumed.
 *
 * A `static` property on `Role`, or a plain `singleton`, would survive from one
 * job to the next inside a long-lived worker. That is precisely the leak this
 * task exists to prevent: a worker that booted before the roles table was
 * seeded would carry its answer into every job it later ran.
 *
 * A MISSING ROLE IS NEVER MEMOIZED, AND THAT IS THE POINT OF THE NULL CHECK
 * ------------------------------------------------------------------------
 * `$memoized !== null` is the resolution test on purpose, so a null answer is
 * re-read on the next call instead of being frozen for the rest of the request.
 * Without that, one call made before the canonical role exists — during
 * bootstrap, during `RolePermissionSeeder`, or in a test that seeds after its
 * first permission check — would cache "no super-admin role" and turn every
 * later `isSuperAdmin()` in that request into a permanent false.
 *
 * A resolved-flag would be the usual shape here and would be wrong. The cost of
 * omitting it is one extra query in exactly the case where the role does not
 * exist yet, which is not a case anybody runs at volume.
 */
final class SuperAdminRoleId
{
    /**
     * The resolved key, or null while it has not been found.
     *
     * Null doubles as "not resolved" — see the class docblock.
     */
    private int|string|null $memoized = null;

    /**
     * The super-admin role's primary key, or null if that role does not exist.
     */
    public function value(): int|string|null
    {
        if ($this->memoized !== null) {
            return $this->memoized;
        }

        return $this->memoized = $this->read();
    }

    /**
     * The fresh lookup, moved here verbatim from `Role::superAdminId()`.
     *
     * Keyed on name AND guard so a role of the same name on another guard
     * cannot answer for this one.
     */
    private function read(): int|string|null
    {
        /** @var Role|null $role */
        $role = Role::query()
            ->where('name', Role::SUPER_ADMIN)
            ->where('guard_name', config('auth.defaults.guard'))
            ->first();

        $key = $role?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
