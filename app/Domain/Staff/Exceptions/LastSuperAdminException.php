<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

class LastSuperAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The last active super admin cannot be removed or deactivated.');
    }
}
