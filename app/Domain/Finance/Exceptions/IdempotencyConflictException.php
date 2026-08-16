<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A payment's idempotency key was reused for a request that does not match
 * what the key was first recorded against.
 *
 * Design section 5: `payments.idempotency_key` exists so a double-clicked
 * collection button is one payment, not two — but the key alone cannot carry
 * that promise. A key reused with a different bill or a different tender
 * breakdown would hand back an unrelated receipt for money that was never
 * recorded, so `RecordPaymentAction::replayOrConflict()` compares the
 * colliding request's fingerprint to the one already stored under the key
 * and throws this the moment they disagree — never the existing payment, and
 * nothing is written.
 *
 * The specifics stay on the properties rather than the message, for the same
 * reason `ChargeAmountBelowAllocatedException` gives: an exception's public
 * properties are read by whatever renders the refusal, and the sentence
 * itself carries no figures to keep in sync with them.
 */
final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly int $existingPaymentId,
    ) {
        parent::__construct(__('payments.idempotency_conflict'));
    }
}
