<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use InvalidArgumentException;

/**
 * The intent of "the centre will never collect this debt, and here is why".
 *
 * `chargeId` rather than a `Charge` instance — see `AdjustChargeData` for the
 * full reasoning, which applies identically here: `WriteOffChargeAction`
 * re-reads and locks the row itself, under a fresh idempotence check, so a
 * caller's own copy is never the one the decision is made against.
 *
 * The reason is validated here rather than in the Action, for the same reason
 * `AdjustChargeData` validates its own: a blank reason is not a valid reason,
 * this is a property of the value rather than of the operation, and the
 * Filament form marking the field required means reaching this constructor
 * with a blank one is a hand-built payload, not a user mistake.
 */
final readonly class WriteOffChargeData
{
    public function __construct(
        public int $chargeId,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Writing off a charge requires a reason.');
        }
    }
}
