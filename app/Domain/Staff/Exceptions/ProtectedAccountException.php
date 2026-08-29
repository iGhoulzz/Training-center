<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

final class ProtectedAccountException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('credentials.protected_account'));
    }
}
