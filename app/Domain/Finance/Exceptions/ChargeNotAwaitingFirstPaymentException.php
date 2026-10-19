<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A first-payment-only collection reached a bill that no longer awaits its first payment.
 *
 * RAISED INSIDE `RecordPaymentAction`, UNDER THE CHARGE ROW'S LOCK, and only for a
 * `RecordPaymentData` built with `firstPaymentOnly: true`. The collection panel's own
 * pre-check is a courtesy that runs before that lock exists, so two panels on one unpaid
 * bill can both pass it; this is the check that cannot be raced. Design section 12 allows
 * the first payment through the interface and no later installment, and this is where that
 * rule is enforced.
 *
 * ONE REFUSAL FOR EVERY REASON, like the panel's own: a bill that now has a live payment,
 * one that was written off meanwhile, and one with nothing outstanding all read the same,
 * so the message confirms nothing about which of them it was.
 */
final class ChargeNotAwaitingFirstPaymentException extends RuntimeException
{
    public function __construct(public readonly int $chargeId)
    {
        parent::__construct(__('collect.charge_not_collectable'));
    }
}
