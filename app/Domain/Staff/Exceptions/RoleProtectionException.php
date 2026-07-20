<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown when someone attempts to mutate the super_admin role row itself:
 * renaming it, deleting it, taking its name for another role, or stripping its
 * permissions.
 *
 * The super_admin role is the anchor every rank check resolves against. It is
 * protected by the same reasoning that makes the activity log append-only — no
 * role, not even a super admin, may weaken it. Enforcement lives on the Role
 * model (see App\Models\Role) rather than only in a policy, because Shield's
 * role pages write straight through Eloquent and Spatie's relation helpers.
 *
 * Extends AuthorizationException so Filament renders it as a 403 rather than a
 * 500. Messages route through __(); Task 14 supplies the lang/ files.
 */
class RoleProtectionException extends AuthorizationException
{
    public static function superAdminRoleImmutable(): self
    {
        return new self(__('staff.role.super_admin_immutable'));
    }

    public static function superAdminNameReserved(): self
    {
        return new self(__('staff.role.super_admin_name_reserved'));
    }

    public static function superAdminRoleUndeletable(): self
    {
        return new self(__('staff.role.super_admin_undeletable'));
    }

    public static function superAdminPermissionsLocked(): self
    {
        return new self(__('staff.role.super_admin_permissions_locked'));
    }
}
