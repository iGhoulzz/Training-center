<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Data\EnrollAndBillData;
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
            $discount = $this->authorizedDiscount($actor, $data->discountId);

            $enrollment = $this->enrollStudent->execute($actor, new EnrollStudentData(
                studentId: $data->studentId,
                batchId: $data->batchId,
            ));

            $batch = Batch::query()->findOrFail($data->batchId);

            $this->issueCharge->execute($actor, $enrollment, $batch, $discount);

            return $enrollment;
        });
    }

    /**
     * The chosen discount, once the actor has proved they may choose one.
     *
     * The ability is checked before the definition is loaded, so a refusal
     * cannot be told apart from a refusal on an id that does not exist. An
     * inactive definition is NOT filtered out here: `is_active` decides what the
     * UI offers on new enrolments (design section 3), and a server-side filter
     * would turn a crafted id into a silent full-price bill rather than a
     * refusal — the caller asked for something specific and did not get it.
     */
    private function authorizedDiscount(User $actor, ?int $discountId): ?Discount
    {
        if ($discountId === null) {
            return null;
        }

        Gate::forUser($actor)->authorize('apply_discount');

        return Discount::query()->findOrFail($discountId);
    }
}
