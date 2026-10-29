<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A collectable-only collection reached a bill that can no longer take a payment.
 *
 * RAISED INSIDE `RecordPaymentAction`, UNDER THE CHARGE ROW'S LOCK, and only for a
 * `RecordPaymentData` built with `collectableOnly: true`. The collection panel's own
 * pre-check is a courtesy that runs before that lock exists, so two panels on one bill
 * can both pass it; this is the check that cannot be raced. A bill is collectable while
 * it is not written off and something is outstanding (design section 12), and a payment
 * already on it does not change that.
 *
 * ONE REFUSAL FOR EVERY REASON, like the panel's own: a bill that was written off
 * meanwhile and one another session has paid in full read the same, so the message
 * confirms nothing about which of them it was. A collection that merely asks for more
 * than the bill still owes is a different refusal, `PaymentExceedsOutstandingException`,
 * because the operator can correct that one.
 */
final class ChargeNotCollectableException extends RuntimeException
{
    public function __construct(public readonly int $chargeId)
    {
        parent::__construct(__('collect.charge_not_collectable'));
    }
}
