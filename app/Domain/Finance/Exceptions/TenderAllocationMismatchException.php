<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A payment's tenders do not sum to the amount it claims to allocate.
 *
 * Design section 5: "300 on card and 700 in cash" is one payment because the
 * two tenders sum to the one allocation. A submission where they do not — a
 * form bug, a hand-built payload, or an operator's arithmetic slip carried
 * through to the request — describes money that was never actually handed
 * over in the amount claimed, and is refused rather than recorded as
 * something the two sides quietly disagree about forever.
 *
 * `PaymentInvariantService::assertRecordable()` checks this BEFORE the
 * overpayment check, because a mismatched request tells nothing true about
 * what the bill still owes — checking outstanding first would raise the
 * wrong refusal for a submission that is simply inconsistent with itself.
 *
 * Both amounts are carried as the decimal strings `Money::toDecimal()`
 * produces, not as `Money` itself — the same reasoning
 * `ChargeAmountBelowAllocatedException` records: an exception's public
 * properties are read by whatever renders the refusal, and `Money`
 * deliberately has no string conversion so nothing casts it by accident.
 */
final class TenderAllocationMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $chargeId,
        public readonly string $tenderTotal,
        public readonly string $allocationAmount,
    ) {
        parent::__construct(__('payments.tender_allocation_mismatch'));
    }
}
