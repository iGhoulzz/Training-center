<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Services\PricingService;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Raise the one bill an enrolment carries.
 *
 * INTERNAL. THE ONLY CALLER IS EnrollAndBillAction.
 * -------------------------------------------------
 * There is no ability to "issue a charge" and design section 4 says why: billing
 * is not a decision anybody makes, it is what enrolling *is*. `create_charge` is
 * deliberately not seeded and `ChargePolicy::create()` refuses unconditionally,
 * so this Action authorizes nothing itself — the entitlement it runs under is
 * `create` on Enrollment, checked by its caller before either row exists.
 *
 * That makes it the second of the internal collaborators the write-boundary
 * architecture test knows about, alongside SystemRoleWriter: an Action with no
 * actor check of its own, reachable from exactly one place, and named in that
 * test so the exemption is deliberate rather than an oversight.
 *
 * EVERY FIGURE IS FROZEN HERE, AND THE PRICE IS READ ONCE
 * ------------------------------------------------------
 * `list_price`, `discount_percentage` and `amount` are written from the values
 * true at this instant and never recomputed (design section 3). The list price
 * comes from PricingService, which resolves a null batch price to the course
 * default live — that inheritance is a *read* rule, and this is the moment it
 * stops applying to this bill.
 *
 * The percentage is copied off the definition rather than referenced through it,
 * because a definition can be deactivated or replaced and a receipt reprinted
 * three years later still has to show the figure that was charged.
 *
 * THE `CHG-` REFERENCE IS WRITTEN TWICE, IN THE CALLER'S TRANSACTION
 * -----------------------------------------------------------------
 * Same mechanism, and the same reason, as EnrollStudentAction's `ENR-`: the
 * reference contains the row's own id, `charges.reference` is NOT NULL UNIQUE,
 * and MySQL will not let a generated column read an AUTO_INCREMENT column. The
 * insert carries Reference::placeholder() and is updated to the real value
 * before the surrounding transaction commits, so no other connection sees a
 * placeholder. `reference` is excluded from Charge::auditedAttributes(), which
 * keeps the placeholder out of the append-only log.
 *
 * THE CAUSER IS FORCED, BECAUSE THIS ACTION IS ACTOR-FIRST
 * -------------------------------------------------------
 * RecordsActivity resolves the causer from the authenticated session, and this
 * Action is passed its actor. P2-T05 shipped that exact gap in
 * WriteOffChargeAction: a console invocation recorded no causer and a request
 * made while somebody else held the session recorded the wrong person. The
 * write runs inside CauserResolver::withCauser() for the same reason here.
 */
final class IssueChargeAction
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @param  Enrollment  $enrollment  Already created, inside the caller's transaction.
     * @param  Discount|null  $discount  Already authorized by the caller when present.
     */
    public function execute(User $actor, Enrollment $enrollment, Batch $batch, ?Discount $discount = null): Charge
    {
        return DB::transaction(function () use ($actor, $enrollment, $batch, $discount): Charge {
            $listPrice = $this->pricing->priceForBatch($batch);

            $percentage = $discount?->percentage;

            $amount = $percentage === null
                ? $listPrice
                : $listPrice->afterDiscount($percentage);

            return $this->causers->withCauser($actor, function () use (
                $enrollment,
                $listPrice,
                $discount,
                $percentage,
                $amount,
            ): Charge {
                $charge = Charge::create([
                    'enrollment_id' => $enrollment->getKey(),
                    // Replaced below, before this transaction commits.
                    'reference' => Reference::placeholder(),
                    'list_price' => $listPrice->toDecimal(),
                    'discount_id' => $discount?->getKey(),
                    'discount_percentage' => $percentage,
                    'amount' => $amount->toDecimal(),
                    /*
                     * Design section 4: the due date IS the enrolment date. The
                     * centre bills at enrolment and expects payment at or near
                     * it; there is no invoicing term to express, and aging has
                     * to measure from the day the debt was incurred.
                     *
                     * ON THE CENTRE'S CALENDAR, NOT UTC. `enrolled_at` is stored
                     * UTC, and letting the `date` cast truncate it writes the
                     * wrong DAY for every enrolment taken between midnight and
                     * 02:00 in Tripoli — the debt would age from the day before
                     * the one the centre experienced. This is the exact failure
                     * CentreCalendar's own docblock records under "what went
                     * wrong before it existed"; the independent review found it
                     * reintroduced here.
                     */
                    'due_date' => CentreCalendar::localise($enrollment->enrolled_at)->toDateString(),
                ]);

                $charge->update([
                    'reference' => Reference::format(
                        Reference::CHARGE_PREFIX,
                        /*
                         * FROM `enrolled_at`, THE SAME INSTANT `ENR-` IS BUILT
                         * FROM — not from the already-truncated `due_date`.
                         *
                         * Reading the year off a `date` column that had been
                         * truncated in UTC produced `ENR-2026-…` and
                         * `CHG-2025-…` for one act, at 00:30 Tripoli on New
                         * Year's Day. Two references for the same enrolment
                         * disagreeing about which year it happened in is the
                         * kind of defect nobody finds until an auditor does.
                         */
                        CentreCalendar::yearOf($enrollment->enrolled_at),
                        (int) $charge->getKey(),
                    ),
                ]);

                return $charge;
            });
        });
    }
}
