<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\StudentNotDeletableException;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete (soft-delete) a student, refusing while the student still matters to the
 * centre's books (QA Q2, owner decision of 2026-10-17: "refuse while owing").
 *
 * Two refusals, each a StudentNotDeletableException carrying the rule's own
 * sentence so the caller can show it as it stands:
 *
 *   - an ACTIVE enrolment: the student is, as far as the register goes, studying
 *     here. Withdraw or complete it first.
 *   - an OUTSTANDING BALANCE greater than zero: the centre is owed money.
 *
 * THE BALANCE IS StudentBalanceQuery'S, NOT A SECOND SUM. The published Finance
 * reader the student list and the portal already use, so this rule and the figure
 * on screen cannot disagree. It counts every enrolment whatever its status
 * (withdrawing is not a financial event, design 4) and does not exclude a
 * written-off charge - which means a student whose debt was written off still
 * reads as owing here, exactly as the list shows it. Money stays decimal(12,3)
 * Money throughout; nothing is rounded or cast to a float.
 *
 * Soft deleting is what it always was: the row leaves the register and survives
 * for the enrolments and paperwork that reference it.
 *
 * Actor first and self-authorizing, like every other request-path Action here:
 * the check is inside the Action so the answer binds every caller - the table
 * row, the edit page, a console command - and the buttons' own authorize() is
 * only the UX half.
 *
 * EVERY READ THAT DECIDES THE DELETE IS A LOCKING READ, TAKEN IN ONE ORDER, in one
 * transaction with the delete itself:
 *
 *   1. the student row;
 *   2. the student's active enrolments (so an enrolment withdrawn or completed
 *      meanwhile is seen as it now is);
 *   3. through StudentBalanceQuery::totalForUpdate(): the student's enrolments
 *      with their charges, then for each charge the allocations and payments it
 *      is paid by - the same locking form ChargeBalance gives the Actions that
 *      write those rows;
 *   4. the student row's soft delete.
 *
 * WHY NOT THE ORDINARY READS, WHICH THIS ACTION USED TO TAKE. Holding the student
 * row does not stop the Finance Actions: AdjustChargeAction, ReversePaymentAction,
 * WriteOffChargeAction and RecordPaymentAction lock a charge or a payment and never
 * the student. Under REPEATABLE READ a plain SELECT is served from the snapshot of
 * the transaction's first consistent read, so a charge raised by a thousandth of a
 * dinar (or a payment reversed) by one of them AFTER that snapshot and BEFORE the
 * balance was summed was invisible, and a student who now owed was deleted
 * (docs/ENGINEERING.md, "A locking read is the only read that is current inside a
 * transaction"). A locking read reads the latest committed rows and, when one of
 * those Actions still holds a row, waits for it. The check is
 * DeleteStudentConcurrencyTest, which commits exactly that change between the
 * snapshot and the decision.
 *
 * LOCK ORDER, CHECKED AGAINST EACH WRITER (the order a lock is taken in is the
 * contract; a writer taking any pair of these in the opposite order can deadlock):
 *
 *   - EnrollStudentAction / EnrollAndBillAction: batch, then student, then the new
 *     enrolment and charge, which nobody else can see yet. Student before
 *     enrolment, as here. Enrolments are created only under the student row, so
 *     the set read in step 3 cannot grow while this holds it.
 *   - Withdraw / Complete / Delete enrolment (EnrollmentMutex): batch, then the
 *     enrolment; Delete then goes on to the charge and its allocations. Enrolment
 *     before charge before allocations, as here. They take no student row.
 *   - AdjustChargeAction: the charge, then allocatedForUpdate() - charge before
 *     allocations, as here. WriteOffChargeAction: the charge alone.
 *   - ReversePaymentAction: the payment row alone, which step 3 reaches last.
 *   - RecordPaymentAction: the charge, THEN the student, because inserting a
 *     payment takes a shared lock on its student's row for the foreign key. That
 *     is the opposite order to step 1 then step 3, so a payment recorded for this
 *     student while it is being deleted can deadlock with it. InnoDB resolves a
 *     deadlock by rolling one side back: RecordPaymentAction retries itself, and
 *     this Action retries too (DEADLOCK_ATTEMPTS), so neither a payment nor a
 *     delete is lost - and a delete that does retry decides again from the then
 *     current rows. It cannot be removed from this side: the student row must be
 *     held first, since that is what serialises enrolments.
 *
 * Under the locks the balance is still StudentBalanceQuery's: totalForUpdate() sums
 * ChargeBalance's own locking figure per charge, and DeleteStudentRuleTest pins it equal to
 * forStudent() across paid, part-paid, adjusted, reversed and written-off charges.
 */
final class DeleteStudentAction
{
    /**
     * How many times the transaction is run when InnoDB names it a deadlock
     * victim (see the lock-order section above). Only engages when this is the
     * outermost transaction, as Laravel's retry always does.
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly StudentBalanceQuery $balances) {}

    /**
     * @throws StudentNotDeletableException if the student has an active enrolment or owes money.
     */
    public function execute(User $actor, Student $student): void
    {
        Gate::forUser($actor)->authorize('delete', $student);

        DB::transaction(function () use ($student): void {
            $held = Student::query()->lockForUpdate()->find($student->getKey());

            // Already deleted by someone else a moment ago: nothing left to do.
            if (! $held instanceof Student) {
                return;
            }

            // A locking read: the plain exists() answered from the transaction's
            // snapshot, which an enrolment change committed since would not be in.
            $hasActiveEnrollment = $held->enrollments()
                ->where('status', EnrollmentStatus::Active)
                ->lockForUpdate()
                ->first(['id']) !== null;

            if ($hasActiveEnrollment) {
                throw StudentNotDeletableException::hasActiveEnrollment((int) $held->getKey());
            }

            if ($this->balances->totalForUpdate((int) $held->getKey())->isPositive()) {
                throw StudentNotDeletableException::owesBalance((int) $held->getKey());
            }

            $held->delete();
        }, self::DEADLOCK_ATTEMPTS);
    }
}
