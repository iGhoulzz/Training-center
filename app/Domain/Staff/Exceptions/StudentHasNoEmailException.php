<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

final class StudentHasNoEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('credentials.student_has_no_email'));
    }
}
