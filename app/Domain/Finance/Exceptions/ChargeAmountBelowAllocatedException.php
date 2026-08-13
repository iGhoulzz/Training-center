<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * An adjustment tried to set a charge's amount below what has already been
 * allocated to it from payments that still stand.
 *
 * Design section 4: "The Action refuses to drop the amount below what has
 * already been allocated." A charge whose amount fell below its allocated
 * total would describe a bill smaller than the money already recorded
 * against it — `ChargeBalance`'s outstanding figure would go negative, and
 * every report and receipt reading it would be describing a debt that never
 * existed.
 *
 * Both amounts are carried as the decimal strings `Money::toDecimal()`
 * produces, not as `Money` itself — an exception's public properties are
 * read by whatever renders the refusal, and `Money` deliberately has no
 * string conversion (see its own docblock) so that nothing casts it by
 * accident. The exception message goes through `__()` because
 * `AdjustChargeAction` is reached from Filament and this reaches the panel as
 * a notification.
 */
final class ChargeAmountBelowAllocatedException extends RuntimeException
{
    public function __construct(
        public readonly int $chargeId,
        public readonly string $attemptedAmount,
        public readonly string $allocatedAmount,
    ) {
        parent::__construct(__('charges.amount_below_allocated'));
    }
}
