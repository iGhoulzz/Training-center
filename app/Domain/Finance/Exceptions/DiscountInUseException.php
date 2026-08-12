<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/** A discount definition cannot be deleted after a bill has referenced it. */
final class DiscountInUseException extends RuntimeException
{
    public function __construct(public readonly int $discountId)
    {
        parent::__construct(__('pricing.discount_in_use'));
    }
}
