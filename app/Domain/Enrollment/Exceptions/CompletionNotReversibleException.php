<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * A completion cannot be reversed right now, for one of two unrelated reasons.
 *
 * TWO NAMED CONSTRUCTORS, ONE CLASS — THE SAME SHAPE AS
 * ChargeAlreadyCommittedException
 * ------------------------------------------------------------------------
 * `wrongStatus()` and `certificateStillValid()` are different facts about the
 * row, and a caller catching this exception type needs to be able to tell them
 * apart: the first means "this row is not in a state reversal even applies to"
 * (active or withdrawn), and the second means "the completion is real and a
 * certificate still stands on it — revoke the certificate first." Collapsing
 * both into one generic "cannot reverse" message would send an actor looking
 * for the wrong fix.
 *
 * EVERY TRANSLATION KEY IS SPELLED OUT IN FULL, NOT ASSEMBLED.
 * `LocalizationTest` resolves `__()` keys by scanning literal calls in source;
 * a key built as `'enrollment.completion_not_reversible_'.$reason` would be
 * invisible to that scan and could go untranslated silently. The match below
 * keeps both keys as literal strings a static reader can find.
 */
final class CompletionNotReversibleException extends RuntimeException
{
    private const WRONG_STATUS = 'wrong_status';

    private const CERTIFICATE_VALID = 'certificate_valid';

    private function __construct(
        public readonly int $enrollmentId,
        public readonly string $reason,
    ) {
        parent::__construct(match ($reason) {
            self::WRONG_STATUS => __('enrollment.completion_not_reversible_status'),
            self::CERTIFICATE_VALID => __('enrollment.completion_not_reversible_certificate'),
            /*
             * Unreachable by construction — the constructor is private and the
             * two factories below are the only way in. Written out because
             * PHPStan cannot prove that from a `string`, and because this names
             * what went wrong at the throw site instead of letting an
             * UnhandledMatchError stand in for a missing-string label.
             */
            default => throw new InvalidArgumentException(
                "Unknown completion-reversal refusal reason [{$reason}]."
            ),
        });
    }

    /** The row is active or withdrawn — reversal only ever applies to a completed one. */
    public static function wrongStatus(int $enrollmentId): self
    {
        return new self($enrollmentId, self::WRONG_STATUS);
    }

    /** A valid certificate still stands on this completion; revoke it first. */
    public static function certificateStillValid(int $enrollmentId): self
    {
        return new self($enrollmentId, self::CERTIFICATE_VALID);
    }
}
