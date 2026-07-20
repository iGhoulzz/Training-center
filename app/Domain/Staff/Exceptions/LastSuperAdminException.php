<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * Guard 3: the system refuses to lose its last active super admin, whether by
 * deletion, deactivation, or stripping the super_admin role — from any surface,
 * including seeders and tinker.
 *
 * The message routes through __() per the no-hardcoded-strings rule; Task 14
 * supplies the lang/ files.
 */
class LastSuperAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('staff.escalation.last_super_admin'));
    }
}
