<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Support\Money;
use InvalidArgumentException;

/**
 * The intent of "this charge's amount was entered wrong, and here is why".
 *
 * IDENTIFIER, NOT A MODEL — SEE AssignInstructorData
 * ---------------------------------------------------
 * `chargeId` rather than a `Charge` instance, for the reason that class's
 * docblock gives: `AdjustChargeAction` re-reads and locks the row itself, and
 * handing the Action a model would invite the assumption that the passed
 * instance is the one being authorized and checked against. It is not — a
 * caller holding a stale copy from before a concurrent payment could otherwise
 * make a decision against figures that are no longer true.
 *
 * THE AMOUNT ARRIVES AS A STRING AND LEAVES AS Money
 * ----------------------------------------------------
 * `Money::fromDecimal()` is built for exactly this input — "what a form
 * submits" (see its own docblock) — so the parsing and the precision guard
 * (no fourth decimal place, no float) happen once, here, rather than being
 * repeated by every caller that constructs this DTO. A malformed value never
 * reaches the Action at all; it fails at the boundary, in the constructor.
 *
 * THE REASON IS VALIDATED HERE, NOT LEFT TO THE ACTION
 * -------------------------------------------------------
 * Design section 4 makes the reason mandatory. Blank-string rejection is a
 * property of the *value* rather than of the operation — same reasoning as
 * `AssignInstructorData`'s hour range — so it belongs on the DTO and every
 * caller gets it for free. This is a programming-error guard, not a business
 * rule an end user is expected to trigger: the Filament form marks the field
 * required, so reaching this constructor with a blank reason means a
 * hand-built payload or a console typo.
 */
final readonly class AdjustChargeData
{
    public Money $amount;

    public function __construct(
        public int $chargeId,
        string $amount,
        public string $reason,
    ) {
        $this->amount = Money::fromDecimal($amount);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A charge adjustment requires a reason.');
        }
    }
}
