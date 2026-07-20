<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown when an authenticated actor attempts a role or permission change that
 * the escalation guards forbid.
 *
 * Extends AuthorizationException so Laravel and Filament render it as a 403
 * rather than a 500.
 *
 * Messages route through __() per the project's no-hardcoded-strings rule.
 * Task 14 supplies the lang/ files; until then the keys render as keys, which
 * is expected and correct.
 */
class RoleEscalationException extends AuthorizationException
{
    public static function cannotGrantSuperAdmin(): self
    {
        return new self(__('staff.escalation.cannot_grant_super_admin'));
    }

    public static function cannotModifyOwnRoles(): self
    {
        return new self(__('staff.escalation.cannot_modify_own_roles'));
    }

    public static function cannotModifyOwnPermissions(): self
    {
        return new self(__('staff.escalation.cannot_modify_own_permissions'));
    }

    /**
     * Finding 4: a role write by an authenticated actor requires the
     * assign_role ability. Without this, a staff account assigned admin to
     * another user directly through the model layer.
     */
    public static function cannotAssignRoles(): self
    {
        return new self(__('staff.escalation.cannot_assign_roles'));
    }

    /**
     * Finding 4: a direct permission grant/revoke on another account requires
     * the assign_role ability, the same capability that gates role writes.
     */
    public static function cannotManagePermissions(): self
    {
        return new self(__('staff.escalation.cannot_manage_permissions'));
    }

    /**
     * Finding 5: guard 1 enforced at the model layer. A non-super-admin actor
     * may not update, delete, or otherwise write a super admin account, even on
     * a direct Eloquent write that never consults UserPolicy.
     */
    public static function cannotManageSuperAdmin(): self
    {
        return new self(__('staff.escalation.cannot_manage_super_admin'));
    }
}
