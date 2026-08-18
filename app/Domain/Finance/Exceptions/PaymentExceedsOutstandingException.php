<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A payment tried to allocate more than the bill still owes.
 *
 * Design section 5: outstanding is derived under the charge row's own lock
 * — `PaymentInvariantService::lockCharge()` — so this is checked from a
 * figure that cannot move underneath the write. That is what turns "a bill
 * settled by someone else between form load and submit" into a clean
 * refusal here rather than a charge whose balance goes negative.
 *
 * Checked SECOND, after `TenderAllocationMismatchException` — see that
 * exception's own docblock for why a self-inconsistent request is refused
 * before its total is even compared to what remains owed.
 *
 * Both amounts are carried as the decimal strings `Money::toDecimal()`
 * produces, not as `Money` itself, for the same reason
 * `ChargeAmountBelowAllocatedException` gives: an exception's public
 * properties are read by whatever renders the refusal, and `Money`
 * deliberately carries no string conversion so nothing casts it by
 * accident.
 */
final class PaymentExceedsOutstandingException extends RuntimeException
{
    public function __construct(
        public readonly int $chargeId,
        public readonly string $attemptedAmount,
        public readonly string $outstandingAmount,
    ) {
        parent::__construct(__('payments.exceeds_outstanding'));
    }
}
