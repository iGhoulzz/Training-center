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
use Illuminate\Support\Facades\Gate;

/**
 * Take a student off a batch.
 *
 * THE ONLY STATUS TRANSITION PHASE 1 HAS.
 * ---------------------------------------
 * Active → Withdrawn, and nothing else. Completion marking belongs to phase 3
 * (spec line 71), so no CompleteEnrollmentAction exists and no form anywhere
 * offers a status field. If a later task appears to need generic status editing,
 * that is a signal to check the spec rather than to add a setter.
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
 * Gate::authorize() is NOT what decides this write. The registered policy reads
 * the pivot with an ordinary SELECT, and under REPEATABLE READ that is served
 * from the snapshot this transaction established with its own first statement —
 * before the batch mutex was taken. An assignment revoked in that window would
 * still read as present.
 *
 * So the rule is asked again with locking: true, after the mutex, and THAT answer
 * is final. The Gate is then re-entered only to produce the refusal, so the
 * response comes from the policy rather than being invented here — the same
 * reason AssignInstructorAction re-enters it. If the Gate disagrees, the locking
 * read wins and the refusal is thrown by hand, because disagreeing is precisely
 * the stale-snapshot case this exists to catch.
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

            $this->authorize($actor, $held->enrollment, $held->batchId);

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

    /**
     * Decide from the locking read; surface the refusal through the policy.
     *
     * See the class docblock for why the locking read is the one that binds.
     */
    private function authorize(User $actor, Enrollment $locked, int $batchId): void
    {
        if ($this->rule->allows($actor, $batchId, locking: true)) {
            return;
        }

        // Throws AuthorizationException carrying whatever response the policy
        // produces. Deliberately re-entered rather than thrown by hand.
        Gate::forUser($actor)->authorize('update', $locked);

        // Reached only when the policy's snapshot-served read disagrees with the
        // locking one. The locking read is the current state, so it wins.
        throw new AuthorizationException(__('enrollment.enrollment_change_denied'));
    }
}
