<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

/**
 * The intent of "put this student on this batch and bill them for it".
 *
 * IDENTIFIERS, NOT MODELS — see AdjustChargeData for the full reasoning. The
 * Action re-reads and locks the batch and the student itself, and handing it
 * loaded models would invite the assumption that the instances passed in are the
 * ones authorized and checked against.
 *
 * THE DISCOUNT IS OPTIONAL AND ITS ABSENCE IS NOT A DEFAULT.
 * A null `discountId` means "full price", which is what a staff member's
 * enrolment always is: design section 3 allows one optional discount per
 * enrolment, gated on `apply_discount`, and staff hold no such grant. It is
 * nullable here rather than absent so that a caller cannot express "apply a
 * discount" by omission or "no discount" by a sentinel value.
 */
final readonly class EnrollAndBillData
{
    public function __construct(
        public int $studentId,
        public int $batchId,
        public ?int $discountId = null,
    ) {}
}
