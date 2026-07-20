<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown when an authenticated actor attempts a role or permission change that
 * escalation guards 1 and 2 forbid.
 *
 * Extends AuthorizationException so Laravel and Filament render it as a 403
 * rather than a 500.
 */
class RoleEscalationException extends AuthorizationException
{
    public static function cannotGrantSuperAdmin(): self
    {
        return new self('Only a super admin may grant or revoke the super_admin role.');
    }

    public static function cannotModifyOwnRoles(): self
    {
        return new self('You cannot modify your own roles.');
    }

    public static function cannotModifyOwnPermissions(): self
    {
        return new self('You cannot modify your own permissions.');
    }
}
