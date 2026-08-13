<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A second write-off was attempted against a charge that already carries one.
 *
 * Design section 4: "Refuse to write off a charge that is already written
 * off (idempotence is not the same as silently re-stamping an actor and time
 * over an earlier decision)." Silently succeeding a second time would let a
 * later actor's name and timestamp quietly overwrite an earlier super
 * admin's decision on the same row — the write-off columns record WHO
 * decided the debt was uncollectable and WHEN, and that is exactly the fact
 * a silent re-stamp destroys.
 *
 * This is deliberately not folded into `ChargePolicy::writeOff()`. Whether
 * an actor may write off charges at all does not depend on any one charge's
 * state — see that method's docblock — so this is a typed business-rule
 * refusal raised by the Action after authorization succeeds, the same shape
 * as `DuplicateEnrollmentException` and `BatchInUseException`.
 */
final class ChargeAlreadyWrittenOffException extends RuntimeException
{
    public function __construct(public readonly int $chargeId)
    {
        parent::__construct(__('charges.already_written_off'));
    }
}
