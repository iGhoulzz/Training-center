<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A deactivated discount definition was chosen for an enrolment.
 *
 * DEACTIVATION HAS TO MEAN SOMETHING ON THE SERVER, NOT ONLY IN THE PICKER.
 * -------------------------------------------------------------------------
 * `EnrollAndBillAction` is the only application path that applies a discount, so
 * if it ignored `is_active` then `DeactivateDiscountAction` would have no effect
 * on enrolment at all: anyone holding `apply_discount` could keep applying a
 * retired definition by id, and the button that retires it would be decoration.
 *
 * REFUSED, NOT SILENTLY DROPPED. Falling back to full price would bill a student
 * a different amount from the one the operator chose, without saying so — a
 * silent, wrong-figure bill is worse than a refusal, and the operator can pick
 * a current definition the moment they are told.
 *
 * A business-rule refusal rather than an authorization one, like
 * `ChargeAlreadyCommittedException`: the actor may apply discounts, and this
 * particular definition is retired.
 */
final class DiscountNotApplicableException extends RuntimeException
{
    public function __construct(public readonly int $discountId)
    {
        parent::__construct(__('billing.discount_not_active'));
    }
}
