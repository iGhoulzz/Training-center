<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

final class EmailAlreadyRegisteredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('credentials.email_already_registered'));
    }
}
