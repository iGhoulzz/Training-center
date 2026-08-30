<?php

declare(strict_types=1);

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Support\Money;

/**
 * A student's whole balance picture, in the shape the portal's "my balance"
 * page renders directly. Design §4.2, §11.4.
 *
 * `total` IS SUMMED IN PHP OVER `$enrollments`, NEVER A SECOND SQL AGGREGATE.
 * ---------------------------------------------------------------------------
 * A separate SQL SUM alongside the per-row figures would be a second
 * definition of the total that could disagree with the rows it is supposedly
 * a total of — the same shape design §4 forbids for a stored `paid_amount`.
 * This class carries no arithmetic of its own; StudentBalanceQuery builds
 * `$total` with `Money::add()` folded over `$enrollments` after the query
 * returns, so the two can never drift apart.
 */
final readonly class StudentBalanceSummary
{
    /** @param  array<int, EnrollmentBalance>  $enrollments  keyed by enrolment id */
    public function __construct(
        public array $enrollments,
        public Money $total,
    ) {}
}
