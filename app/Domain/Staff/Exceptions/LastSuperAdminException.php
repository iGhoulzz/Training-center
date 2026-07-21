<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * The system refuses to lose its last active super admin, whether by deletion,
 * deactivation, or stripping the super_admin role.
 *
 * This is a business-rule rejection, not an authorization failure: the actor may
 * be perfectly entitled to the operation, but the operation would leave the
 * system unrecoverable. It is thrown by SuperAdminInvariantService and mapped to
 * HTTP 422 in bootstrap/app.php so Filament/Livewire can surface it as a
 * validation-style error rather than a 500.
 *
 * The message routes through __() per the no-hardcoded-strings rule; Task 14
 * supplies the lang/ files (key: staff.escalation.last_super_admin).
 */
class LastSuperAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('staff.escalation.last_super_admin'));
    }
}
