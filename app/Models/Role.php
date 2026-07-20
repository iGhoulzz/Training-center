<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Exceptions\RoleProtectionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The application's Role model, registered via config/permission.php so that
 * Spatie, Filament, and Shield all resolve roles through this class.
 *
 * WHY THIS EXISTS
 * ---------------
 * Task 4 keyed every escalation guard on the role *name* string 'super_admin'
 * while leaving the role row itself unprotected. That is the root flaw the
 * remediation (P1-T04b) closes:
 *
 *   - Renaming the role defeated every hasRole('super_admin') check.
 *   - Deleting the role cascaded away all super-admin assignments.
 *   - Stripping its permissions silently disabled the super admin.
 *   - Spatie's role-side pivot helpers (removeFromModels / syncModels) removed
 *     the last super admin without ever touching User::removeRole().
 *
 * ROOT-CAUSE DECISION: identity is the primary key, and the name is frozen.
 * Super-admin identity is resolved to this role's immutable primary key
 * (superAdminId / isSuperAdmin), so checks compare by id — matching how Spatie
 * actually writes the pivot — and cannot be fooled by an in-memory name
 * mutation. The single name->id anchor is itself protected here: the row cannot
 * be renamed, cannot be deleted, cannot have its name taken by another role,
 * and cannot be stripped of permissions.
 */
class Role extends SpatieRole
{
    /**
     * The canonical name of the super-admin role.
     *
     * The row bearing this name is immutable (see the booted() guards), so this
     * constant is a stable anchor rather than a mutable lookup key.
     */
    public const SUPER_ADMIN = 'super_admin';

    /**
     * Resolve the super-admin role's primary key from the database.
     *
     * Callers compare identity by this key rather than by name, so a check
     * cannot drift from what Spatie writes (Spatie detaches by key). The row is
     * immutable and undeletable, so this lookup is stable for the life of the
     * install.
     */
    public static function superAdminId(): int|string|null
    {
        /** @var self|null $role */
        $role = static::query()
            ->where('name', self::SUPER_ADMIN)
            ->where('guard_name', config('auth.defaults.guard'))
            ->first();

        $key = $role?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * Take a pessimistic lock on the super-admin role row.
     *
     * Every operation that could reduce the active super-admin population locks
     * this single row first, so concurrent removals serialize on it instead of
     * both passing an unlocked count (finding 7). A no-op outside a transaction.
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

    protected static function booted(): void
    {
        /*
         * Finding 1: the super_admin row's identity is frozen. Renaming it, or
         * changing its guard, would silently defeat every rank check. Blocking
         * this at the model layer (not only in RolePolicy) is required because
         * Shield's EditRole page writes the model directly.
         */
        static::updating(function (self $role): void {
            if ($role->isSuperAdmin() && ($role->isDirty('name') || $role->isDirty('guard_name'))) {
                throw RoleProtectionException::superAdminRoleImmutable();
            }

            // And no other role may take the reserved name, which would create a
            // second, unprotected 'super_admin' row.
            if (! $role->isSuperAdmin() && $role->isDirty('name') && $role->name === self::SUPER_ADMIN) {
                throw RoleProtectionException::superAdminNameReserved();
            }
        });

        /*
         * Finding 1: deleting the super_admin role cascades away every
         * assignment via the model_has_roles foreign key. The row is
         * undeletable by anyone, including a super admin.
         */
        static::deleting(function (self $role): void {
            if ($role->isSuperAdmin()) {
                throw RoleProtectionException::superAdminRoleUndeletable();
            }
        });
    }

    /**
     * Finding 2: Shield's role editor calls syncPermissions() on save, which
     * bypasses every User-level guard. Because custom permissions are hidden in
     * Shield's UI, a plain save of the super_admin role would silently strip
     * access_admin_panel, assign_role, and the rest.
     *
     * The super_admin role holds every permission by definition (the seeder
     * establishes this). Rather than error on the role editor's save, any sync
     * on the super_admin role is pinned to the full permission set, so the role
     * can never emerge from a save with fewer permissions than it went in with.
     *
     * @param  string|int|array<int, mixed>|Permission|Collection<int, mixed>|\BackedEnum  ...$permissions
     */
    public function syncPermissions(...$permissions): static
    {
        if ($this->exists && $this->isSuperAdmin()) {
            return parent::syncPermissions($this->getPermissionClass()::all());
        }

        return parent::syncPermissions(...$permissions);
    }

    /**
     * Finding 2 (symmetric): a direct revoke on the super_admin role is refused
     * outright. It holds every permission and may not be weakened.
     *
     * @param  Permission|Permission[]|string|string[]|\BackedEnum  $permission
     */
    public function revokePermissionTo($permission): static
    {
        if ($this->exists && $this->isSuperAdmin()) {
            throw RoleProtectionException::superAdminPermissionsLocked();
        }

        return parent::revokePermissionTo($permission);
    }

    /**
     * Finding 1: Spatie's role-side helper detaches users straight from the
     * pivot, never routing through User::removeRole(), so it removed the last
     * super admin in a probe. Guard 3 is re-asserted after the write, inside a
     * transaction locking the super-admin row, and rolls back if the population
     * would hit zero.
     *
     * @param  Model|int|string|array<int, Model|int|string>|Collection<int, Model|int|string>  $models
     */
    public function removeFromModels(array|Collection|Model|int|string $models, ?string $modelClass = null): static
    {
        if (! $this->isSuperAdmin()) {
            return parent::removeFromModels($models, $modelClass);
        }

        return DB::transaction(function () use ($models, $modelClass): static {
            self::lockSuperAdminRow();
            parent::removeFromModels($models, $modelClass);
            $this->assertActiveSuperAdminRemains();

            return $this;
        });
    }

    /**
     * Finding 1: syncModels() replaces the whole assignment set from the role
     * side, so it too can strip the last super admin. Same post-write, locked
     * re-assertion of guard 3.
     *
     * @param  Model|int|string|array<int, Model|int|string>|Collection<int, Model|int|string>  $models
     */
    public function syncModels(array|Collection|Model|int|string $models, ?string $modelClass = null): static
    {
        if (! $this->isSuperAdmin()) {
            return parent::syncModels($models, $modelClass);
        }

        return DB::transaction(function () use ($models, $modelClass): static {
            self::lockSuperAdminRow();
            parent::syncModels($models, $modelClass);
            $this->assertActiveSuperAdminRemains();

            return $this;
        });
    }

    /**
     * Guard 3 from the role side: at least one active super admin must survive.
     * Counted after the pivot write, inside the caller's locked transaction, so
     * a violation rolls the write back.
     */
    private function assertActiveSuperAdminRemains(): void
    {
        $remaining = User::query()
            ->role(self::SUPER_ADMIN)
            ->where('is_active', true)
            ->count();

        if ($remaining === 0) {
            throw new LastSuperAdminException;
        }
    }
}
