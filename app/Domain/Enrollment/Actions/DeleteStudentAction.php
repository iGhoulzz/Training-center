<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete (soft-delete) a student, refusing while the student still matters to the
 * centre's books (QA Q2, owner decision of 2026-10-17: "refuse while owing").
 *
 * Two refusals, each a ValidationException carrying the rule's own sentence so
 * the caller can show it as it stands:
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
 * THE STUDENT ROW IS LOCKED, then the rules are read, then the delete happens, all
 * in one transaction. EnrollStudentAction takes the same row lock before it writes
 * an enrolment, so an enrolment added while this runs either commits first and is
 * seen here, or waits and then finds a deleted student and is refused.
 */
final class DeleteStudentAction
{
    public function __construct(private readonly StudentBalanceQuery $balances) {}

    /**
     * @throws ValidationException if the student has an active enrolment or owes money.
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

            if ($held->enrollments()->where('status', EnrollmentStatus::Active)->exists()) {
                throw ValidationException::withMessages([
                    'student' => __('enrollment.student_delete_has_active_enrollment'),
                ]);
            }

            if ($this->balances->forStudent((int) $held->getKey())->total->isPositive()) {
                throw ValidationException::withMessages([
                    'student' => __('enrollment.student_delete_owes_balance'),
                ]);
            }

            $held->delete();
        });
    }
}
