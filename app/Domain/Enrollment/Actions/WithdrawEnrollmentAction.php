<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotWithdrawableException;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Domain\Enrollment\Support\EnrollmentUpdateRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Take a student off a batch.
 *
 * ACTIVE → WITHDRAWN, AND NOTHING ELSE.
 * -------------------------------------
 * This was phase 1's only status transition. Phase 3 added the second and third
 * — CompleteEnrollmentAction and ReverseEnrollmentCompletionAction (P3-T03) —
 * and no form anywhere still offers a generic status field. If a later task
 * appears to need one, that is a signal to check the spec rather than to add a
 * setter.
 *
 * THE COMPLETED BRANCH BELOW WAS UNREACHABLE UNTIL P3-T03, and untested with it.
 * Nothing could produce a completed enrolment, so `status !== Active` only ever
 * saw withdrawn rows; the docblock promised the exception and no test executed
 * it. WithdrawCompletedEnrollmentTest now does.
 *
 * NOT GATED ON THE BATCH'S STATUS. Spec line 237 names exactly two operations a
 * closed batch refuses: new enrolments and instructor changes. Withdrawal is
 * neither, and a student recorded in error on a finished batch must stay
 * removable or the mistake is permanent. Somebody will eventually add an
 * acceptsEnrollments() check here because the Action next door has one;
 * EnrollmentTest pins both closed states against exactly that.
 *
 * THE AUTHORIZATION THAT BINDS IS THE LOCKING ONE
 * -----------------------------------------------
 * The registered policy is NOT what decides this write. It reads the pivot with
 * an ordinary SELECT, and under REPEATABLE READ that is served from the snapshot
 * this transaction established with its own first statement — before the batch
 * mutex was taken. An assignment revoked in that window would still read as
 * present.
 *
 * So the rule is asked again with locking: true, after the mutex, and THAT answer
 * is final. A denial is thrown directly from that answer. Re-entering the Gate
 * here would ask the ordinary-read policy for a second, potentially stale answer
 * and would need a separate fallback branch to stop an outdated "allowed"
 * response from reopening the write.
 *
 * IDEMPOTENT, AND TERMINAL
 * ------------------------
 * Withdrawing an already-withdrawn enrolment returns it unchanged rather than
 * throwing: a double-clicked button, or two staff acting on the same row, must
 * not become an error somebody has to interpret. Nothing un-withdraws in phase 1
 * — reinstating a student is a new enrolment, which the unique index would
 * refuse, and that is a phase 2 conversation about what happens to the charges.
 */
final class WithdrawEnrollmentAction
{
    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly EnrollmentUpdateRule $rule,
    ) {}

    /**
     * @throws EnrollmentNotWithdrawableException if the enrolment is completed.
     * @throws EnrollmentBatchChangedException if the enrolment moved batches.
     * @throws AuthorizationException if the actor may not amend this enrolment.
     */
    public function execute(User $actor, Enrollment $enrollment): Enrollment
    {
        return DB::transaction(function () use ($actor, $enrollment): Enrollment {
            $held = $this->mutex->acquire($enrollment);

            $this->authorize($actor, $held->batchId);

            $locked = $held->enrollment;

            if ($locked->status === EnrollmentStatus::Withdrawn) {
                return $locked;
            }

            if ($locked->status !== EnrollmentStatus::Active) {
                throw new EnrollmentNotWithdrawableException((int) $locked->getKey());
            }

            $locked->update(['status' => EnrollmentStatus::Withdrawn]);

            return $locked;
        });
    }

    /** Decide and, when necessary, refuse directly from the binding locking read. */
    private function authorize(User $actor, int $batchId): void
    {
        if (! $this->rule->allows($actor, $batchId, locking: true)) {
            throw new AuthorizationException(__('enrollment.enrollment_change_denied'));
        }
    }
}
