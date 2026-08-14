<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Finance\Exceptions\DiscountNotApplicableException;
use App\Domain\Finance\Models\Discount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The only application path that puts a student on a batch.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * Design section 12: `EnrollmentsRelationManager` called `EnrollStudentAction`
 * directly, which phase 1 was right to do and phase 2 makes into a UI path that
 * creates an enrolment with no bill — silently, on the batch screen staff
 * already use. Every enrolment carries exactly one charge, so enrolling and
 * billing have to be one act or the invariant is a convention.
 *
 * `ActionBoundaryArchTest` asserts that nothing under `app/` calls
 * `EnrollStudentAction` except this class, and the rule is mutation-tested by
 * injecting a violation. `EnrollmentTest` still calls it directly: it is that
 * Action's own test and the rule scans `app/`, not `tests/`.
 *
 * ONE TRANSACTION, AND THE DISCOUNT IS AUTHORIZED BEFORE ANY ROW EXISTS
 * --------------------------------------------------------------------
 * `create` on Enrollment is checked by EnrollStudentAction, where it has always
 * been. `apply_discount` is checked HERE, and before the enrolment is written,
 * because a refusal after the insert would be a rollback of work that should
 * never have started — and because an actor without the ability learns nothing
 * about whether the discount id they guessed exists.
 *
 * A staff member enrolling a walk-in passes no discount and needs no financial
 * ability at all (design section 10): the bill is raised at full price by an
 * internal Action that authorizes nothing, under the `create_enrollment` grant
 * they already hold.
 *
 * THE BATCH IS RE-READ RATHER THAN PASSED THROUGH
 * -----------------------------------------------
 * EnrollStudentAction locks the batch, checks it accepts enrolments, and returns
 * the enrolment. The price is read from the batch afterwards, inside the same
 * transaction, so the figure frozen onto the bill is the one that was true under
 * that lock rather than one read before it.
 */
final class EnrollAndBillAction
{
    public function __construct(
        private readonly EnrollStudentAction $enrollStudent,
        private readonly IssueChargeAction $issueCharge,
    ) {}

    public function execute(User $actor, EnrollAndBillData $data): Enrollment
    {
        return DB::transaction(function () use ($actor, $data): Enrollment {
            /*
             * THE PRIMARY WRITE IS AUTHORIZED AT THIS BOUNDARY, FIRST.
             *
             * EnrollStudentAction checks `create` too, and keeps doing so as
             * defence in depth — but delegating it entirely made this wrapper
             * not self-authorizing for the write it exists to perform, and it
             * let an actor holding `apply_discount` without `create_enrollment`
             * learn whether a discount id was unknown or merely retired before
             * being refused. Cross-review finding: authorization is the first
             * answer this Action gives.
             */
            Gate::forUser($actor)->authorize('create', Enrollment::class);

            $discount = $this->authorizedDiscount($actor, $data->discountId);

            $enrollment = $this->enrollStudent->execute($actor, new EnrollStudentData(
                studentId: $data->studentId,
                batchId: $data->batchId,
            ));

            /*
             * THE PRICE IS FROZEN FROM ROWS THIS TRANSACTION HOLDS.
             *
             * EnrollStudentAction already locked the batch, and a plain re-read
             * here would return this transaction's snapshot rather than the
             * locked version — the independent review captured the SQL and found
             * no `for update` on it, while the docblock above claimed otherwise.
             * Re-taking the lock on a row we already hold is free and makes the
             * claim true.
             *
             * The course matters just as much and is easy to miss: when
             * `batches.price` is null the figure billed comes from
             * `courses.default_price`, and PricingService loads that row through
             * loadMissing() with no lock at all. Locking it here and attaching it
             * makes that load a no-op, so the inherited price cannot move between
             * being read and being frozen.
             */
            $batch = Batch::query()->lockForUpdate()->findOrFail($data->batchId);

            if ($batch->price === null) {
                $batch->setRelation(
                    'course',
                    Course::query()->lockForUpdate()->findOrFail($batch->course_id),
                );
            }

            $this->issueCharge->execute($actor, $enrollment, $batch, $discount);

            return $enrollment;
        });
    }

    /**
     * The chosen discount, once the actor has proved they may choose one.
     *
     * The ability is checked before the definition is loaded, so a refusal
     * cannot be told apart from a refusal on an id that does not exist.
     *
     * A DEACTIVATED DEFINITION IS REFUSED, NOT APPLIED AND NOT IGNORED.
     * This Action is the only application path that applies a discount, so
     * without this check `DeactivateDiscountAction` would have no server-side
     * effect on enrolment whatsoever — a retired definition would stay usable by
     * id forever, and the button that retires it would be decoration. Silently
     * dropping it to full price is the other wrong answer: it bills a figure the
     * operator did not choose, without telling them. The picker offers only
     * active definitions; this is what makes that a rule rather than a courtesy.
     *
     * @throws DiscountNotApplicableException if the definition has been retired.
     */
    private function authorizedDiscount(User $actor, ?int $discountId): ?Discount
    {
        if ($discountId === null) {
            return null;
        }

        Gate::forUser($actor)->authorize('apply_discount');

        /*
         * LOCKED, BECAUSE IT IS A FROZEN PRICING INPUT AND ISSUANCE IS NOT
         * INSTANT.
         *
         * Several reads and two inserts happen between resolving this
         * definition and writing the charge. A plain read leaves that window
         * open: DeactivateDiscountAction can commit `is_active = false` and this
         * transaction would still apply a retired rate — the precise outcome the
         * refusal below exists to prevent — and DeleteDiscountAction can remove
         * an as-yet-unused definition, turning a typed refusal into a raw
         * foreign-key error on insert.
         *
         * The lock also fixes the read ordering: taken here, it is the first
         * statement in this transaction to touch `discounts`, so the row read is
         * the latest committed one rather than a snapshot taken earlier.
         *
         * Lock order is discount → batch → student → course. Nothing else in the
         * system takes a discount lock alongside another row, so this introduces
         * no cycle.
         */
        $discount = Discount::query()->lockForUpdate()->findOrFail($discountId);

        if (! $discount->is_active) {
            throw new DiscountNotApplicableException((int) $discount->getKey());
        }

        return $discount;
    }
}
