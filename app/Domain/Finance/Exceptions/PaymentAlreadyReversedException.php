<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A second reversal was attempted against a payment that already carries one.
 *
 * Design section 5: reversal is a set-once lifecycle transition on an
 * otherwise immutable row. Refusing this outright — rather than silently
 * re-stamping — is not idempotence for its own sake: `reversed_at` and
 * `reversed_by` record WHO decided the money should be given back and WHEN,
 * and a second write over the first would quietly overwrite that decision
 * with a later actor's name and timestamp. Same shape and same reasoning as
 * `ChargeAlreadyWrittenOffException`.
 *
 * This is deliberately not folded into `PaymentPolicy::reverse()`. Whether an
 * actor may reverse payments at all does not depend on any one payment's
 * state — see that method's docblock — so this is a typed business-rule
 * refusal raised by `ReversePaymentAction` after authorization succeeds, the
 * same shape as `ChargeAlreadyWrittenOffException` and `IdempotencyConflictException`.
 */
final class PaymentAlreadyReversedException extends RuntimeException
{
    public function __construct(public readonly int $paymentId)
    {
        parent::__construct(__('payments.already_reversed'));
    }
}
