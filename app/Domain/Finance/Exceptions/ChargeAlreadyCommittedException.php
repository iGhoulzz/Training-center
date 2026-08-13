<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * The bill has been acted on, so the enrolment it belongs to cannot be deleted.
 *
 * A BUSINESS-RULE REFUSAL, NOT AN AUTHORIZATION ONE — the same distinction
 * `ChargeAlreadyWrittenOffException` draws. Whether an actor may delete
 * enrolments at all is a question about the ACTOR, answered by
 * `EnrollmentPolicy::delete()`. Whether this particular bill has money or a
 * human decision attached to it is a question about the ROW, and answering it
 * through the policy would make an admin's refusal look like a missing
 * permission when it is nothing of the kind.
 *
 * WHAT "COMMITTED" MEANS HERE
 * --------------------------
 * Any allocation from any payment, any adjustment recorded in the activity log,
 * or a write-off. Each is a fact somebody produced about money: a payment taken,
 * a figure corrected with a stated reason, or a debt formally retired. Design
 * §12 keeps an enrolment recorded in error deletable — "or the mistake is
 * permanent" — and none of these is a mistake in that sense.
 */
final class ChargeAlreadyCommittedException extends RuntimeException
{
    /** The bill carries an allocation from a payment that still stands. */
    public const ALLOCATED = 'allocated';

    /** Its amount was corrected by AdjustChargeAction, with a stated reason. */
    public const ADJUSTED = 'adjusted';

    /** A super admin retired the debt. */
    public const WRITTEN_OFF = 'written_off';

    /**
     * THE MESSAGE IS TRANSLATED, BECAUSE IT REACHES AN OPERATOR.
     *
     * `EnrollmentsRelationManager::refuse()` puts a domain exception's message
     * straight into a notification, and its docblock records the rule this
     * follows: the domain exceptions carry `__()` messages, and only
     * AuthorizationException needs mapping because Laravel's own message is
     * hardcoded English. A raw string here would be the one untranslated line in
     * an Arabic panel from phase 4.
     *
     * The `$reason` is a KEY, not a sentence, so the three cases keep their own
     * translated lines and callers can still tell them apart programmatically.
     */
    public function __construct(
        public readonly int $chargeId,
        public readonly string $reason,
    ) {
        parent::__construct(__('billing.charge_committed_'.$reason));
    }

    public static function allocated(int $chargeId): self
    {
        return new self($chargeId, self::ALLOCATED);
    }

    public static function adjusted(int $chargeId): self
    {
        return new self($chargeId, self::ADJUSTED);
    }

    public static function writtenOff(int $chargeId): self
    {
        return new self($chargeId, self::WRITTEN_OFF);
    }
}
