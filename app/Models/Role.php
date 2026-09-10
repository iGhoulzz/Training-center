<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Staff\Support\RecordsActivity;
use App\Domain\Staff\Support\SuperAdminRoleId;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The application's Role model, registered via config/permission.php so that
 * Spatie, Filament, and Shield all resolve roles through this class.
 *
 * DELIBERATELY THIN (P1-T04c)
 * ---------------------------
 * The first remediation (P1-T04b) protected the super_admin row by overriding
 * every write path on this model — booted() events, syncPermissions(),
 * revokePermissionTo(), removeFromModels(), syncModels(). That is business
 * logic in a model, which this project forbids, and it produced the same
 * whack-a-mole as the User overrides.
 *
 * Protection of the super_admin role now lives where it belongs:
 *   - RolePolicy denies renaming, deleting, or editing the super_admin role,
 *     and any role the actor holds (the request-path authorization boundary).
 *   - UpdateRolePermissionsAction is the only sanctioned path for role
 *     permission writes and refuses to weaken the super_admin role.
 *   - The (name, guard_name) unique index on `roles` is the database backstop
 *     against a second super_admin row.
 *   - Architecture tests forbid application code from calling the raw Spatie
 *     role-side pivot helpers (assignToModels / removeFromModels / syncModels).
 *
 * This class keeps ONLY the canonical super-admin identity: the frozen name
 * anchor, the id resolver, the row lock used by the invariant service, and the
 * id-based identity test. Identity is resolved to the immutable primary key so
 * checks compare by id — matching how Spatie writes the pivot — and cannot be
 * fooled by an in-memory name mutation.
 */
class Role extends SpatieRole
{
    use RecordsActivity;

    /**
     * Role rows are audited on the MODEL, not only in the role Actions.
     *
     * Shield's role resource creates, renames and deletes roles through ordinary
     * Eloquent writes. Those never reach SyncUserRolesAction or
     * UpdateRolePermissionsAction — which govern who HOLDS a role and what a role
     * MAY DO — so auditing only there would leave the authorization graph
     * rewritable with no trace of who added or removed a role.
     *
     * guard_name is included: moving a role between guards changes what it
     * governs.
     *
     * @return array<int, string>
     */
    public function auditedAttributes(): array
    {
        return ['name', 'guard_name'];
    }

    /**
     * The canonical name of the super-admin role.
     *
     * The row bearing this name is protected by RolePolicy and the unique index
     * on (name, guard_name), so this constant is a stable anchor for identity
     * resolution rather than a value that authorization keys on directly.
     */
    public const SUPER_ADMIN = 'super_admin';

    /**
     * Resolve the super-admin role's primary key.
     *
     * Callers compare identity by this key rather than by name, so a check
     * cannot drift from what Spatie writes (Spatie detaches by key).
     *
     * The query moved to {@see SuperAdminRoleId} (P35-T02), which memoizes it
     * for the life of one request or one queued job. This signature is
     * unchanged so no caller had to move with it. **Only the role id is
     * memoized** — `User::isSuperAdmin()`'s pivot read stays fresh on every
     * call, because that read, not this one, is the authorization decision.
     */
    public static function superAdminId(): int|string|null
    {
        return app(SuperAdminRoleId::class)->value();
    }

    /**
     * Take a pessimistic lock on the super-admin role row.
     *
     * SuperAdminInvariantService locks this single row before counting active
     * super admins, so two concurrent population-reducing operations serialize
     * on it instead of both passing an unlocked count. A no-op outside a
     * transaction.
     */
    public static function lockSuperAdminRow(): void
    {
        static::query()
            ->where('name', self::SUPER_ADMIN)
            ->where('guard_name', config('auth.defaults.guard'))
            ->lockForUpdate()
            ->first();
    }

    /**
     * Is this row the super-admin role? Compared by primary key, not name.
     */
    public function isSuperAdmin(): bool
    {
        $superAdminId = static::superAdminId();
        $key = $this->getKey();

        return $superAdminId !== null
            && (is_int($key) || is_string($key))
            && (string) $key === (string) $superAdminId;
    }
}
