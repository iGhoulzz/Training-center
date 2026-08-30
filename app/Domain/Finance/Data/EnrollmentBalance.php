<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Support\Money;

/**
 * One enrolment's outstanding figure, as the portal's "my balance" page
 * renders one row of it. Design §4.2, §11.4.
 *
 * `chargeId` IS NULLABLE, AND THAT IS A REAL STATE, NOT AN ERROR.
 * -----------------------------------------------------------------
 * Phase 1 enrolments predate billing, and StudentBalanceQuery must answer for
 * every enrolment a student holds, not only the billed ones — the identical
 * reason ChargeQueryService::outstandingForEnrollment() is nullable rather
 * than throwing. `null` here means "no bill exists for this enrolment", and
 * `outstanding` is `Money::zero()` alongside it rather than left null too, so
 * a caller never has to decide what a missing balance means before it can
 * render a row.
 */
final readonly class EnrollmentBalance
{
    public function __construct(
        public int $enrollmentId,
        public ?int $chargeId,
        public Money $outstanding,
    ) {}
}
