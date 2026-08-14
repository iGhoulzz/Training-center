<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use InvalidArgumentException;
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
     *
     * EVERY TRANSLATION KEY IS SPELLED OUT IN FULL, NOT ASSEMBLED.
     * The first version built the key as `'billing.charge_committed_'.$reason`,
     * and LocalizationTest failed it: its scanner reads the literal fragment,
     * found `billing.charge_committed_`, and reported a key resolving to
     * nothing. That is the scanner being right rather than limited — a key no
     * static reader can resolve is a key nobody can audit for a missing Arabic
     * line, which is the entire job of that test three phases before Arabic
     * ships. A `match` also turns an unknown reason into an UnhandledMatchError
     * at the throw site instead of a missing-string label in the panel.
     */
    private function __construct(
        public readonly int $chargeId,
        public readonly string $reason,
    ) {
        parent::__construct(match ($reason) {
            self::ALLOCATED => __('billing.charge_committed_allocated'),
            self::ADJUSTED => __('billing.charge_committed_adjusted'),
            self::WRITTEN_OFF => __('billing.charge_committed_written_off'),
            /*
             * Unreachable by construction — the constructor is private and the
             * three factories below are the only way in. It is written out
             * because PHPStan cannot prove that from a `string`, and because
             * "unhandled match" is the wrong failure for a fourth reason added
             * later: this names what went wrong at the throw site instead of
             * letting a missing-string label appear in the panel.
             */
            default => throw new InvalidArgumentException(
                "Unknown charge commitment reason [{$reason}]."
            ),
        });
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
