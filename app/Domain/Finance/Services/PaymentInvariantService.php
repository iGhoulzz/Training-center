<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException;
use App\Domain\Finance\Exceptions\TenderAllocationMismatchException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;

/**
 * Owns the locked, per-bill payment invariant: a payment is self-consistent
 * and does not exceed what the bill still owes.
 *
 * THE LOCK IS lockCharge()'S JOB, AND EVERY CHECK BELOW ASSUMES IT IS HELD
 * -------------------------------------------------------------------------
 * `RecordPaymentAction` calls `lockCharge()` first, inside its own
 * transaction, and only then calls `assertRecordable()`. This class does not
 * open a transaction of its own — the same shape
 * `CompensationPeriodInvariantService` uses — because a lock taken and
 * released here, outside the caller's transaction, would protect nothing:
 * another connection could still write between this class returning and the
 * caller's insert.
 */
final class PaymentInvariantService
{
    /**
     * Take the bill's row lock and return it.
     *
     * This serialises the tills: a second request for the same bill waits here
     * until the first has committed or rolled back. It is necessary and it is
     * NOT sufficient — see `assertRecordable()`, which has to do more than read
     * a figure once this lock is held.
     */
    public function lockCharge(int $chargeId): Charge
    {
        return Charge::query()->lockForUpdate()->findOrFail($chargeId);
    }

    /**
     * Refuse a payment that is not self-consistent, or that exceeds what the
     * bill still owes.
     *
     * MUST be called inside the transaction holding `lockCharge()`'s lock —
     * see the class docblock. Two checks, in this order:
     *
     * 1. The tenders sum to the allocation. Checked first: a request that is
     *    inconsistent with itself tells nothing true about what the bill
     *    still owes, so checking outstanding before this would raise the
     *    wrong refusal. This one is pure arithmetic on the request and needs
     *    no lock at all.
     * 2. The allocation does not exceed outstanding.
     *
     * OUTSTANDING IS READ THROUGH outstandingForUpdate(), NOT outstandingFor(),
     * AND THE DIFFERENCE IS THE WHOLE INVARIANT.
     * ---------------------------------------------------------------------
     * Holding the charge row is not enough on its own. Under InnoDB's
     * REPEATABLE READ an ordinary read answers from the snapshot this
     * transaction established at its FIRST consistent read — which, in
     * `RecordPaymentAction`, is the permission lookup behind the authorization
     * check, taken before the competing transaction had committed anything.
     *
     * So a second till can block on `lockCharge()`, watch the first commit a
     * payment that settles the bill, acquire the lock, ask what remains, and be
     * told the full amount. It then takes the money again. **Measured, not
     * reasoned about:** with the ordinary read in place,
     * `PaymentConcurrencyTest`'s two-till race recorded 2,000.000 against a
     * 1,000.000 bill, and every single-connection test stayed green.
     *
     * `ChargeBalance::outstandingForUpdate()` is the locking twin that reads
     * the latest committed allocations instead. Its own docblock records what
     * was measured, including the construction that looks like it should work
     * and does not.
     */
    public function assertRecordable(RecordPaymentData $data): Money
    {
        $tenderTotal = $data->tenderTotal();

        if (! $tenderTotal->equals($data->allocation)) {
            throw new TenderAllocationMismatchException(
                $data->chargeId,
                $tenderTotal->toDecimal(),
                $data->allocation->toDecimal(),
            );
        }

        /*
         * DERIVED BY A LOCKING READ, NOT SUMMED HERE.
         * ChargeBalance owns the expression — see its own docblock — so a
         * bill settled by someone else between form load and submit is
         * reflected in this figure rather than in a second computation that
         * could disagree with it. `Money::fromDecimal()` parses digits rather
         * than casting, so the DECIMAL string MySQL returned survives without
         * passing through a float.
         */
        $outstanding = ChargeBalance::outstandingForUpdate($data->chargeId);

        if ($data->allocation->isGreaterThan($outstanding)) {
            throw new PaymentExceedsOutstandingException(
                $data->chargeId,
                $data->allocation->toDecimal(),
                $outstanding->toDecimal(),
            );
        }

        return $outstanding->subtract($data->allocation);
    }
}
