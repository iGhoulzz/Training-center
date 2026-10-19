<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * Nobody deactivates their own account (P4-T33, owner decision of 2026-10-17).
 *
 * Typed, like LastSuperAdminException beside it, so the user form's save hook can
 * catch exactly this refusal and nothing that merely shares its shape: a
 * ValidationException from anywhere else in the save stays a field error.
 */
final class SelfDeactivationException extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct(__('staff.cannot_deactivate_self'));
    }
}
