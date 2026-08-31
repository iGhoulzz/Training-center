<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletableException;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Support\CompletionRule;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Mark a student as having finished a batch — the state a certificate is
 * eventually issued against (T5).
 *
 * ACTIVE -> COMPLETED, AND NOTHING ELSE
 * ---------------------------------------
 * Spec section 5.2's table, exactly: `completed_at` is set from the server
 * clock, never from a caller-supplied value — a client cannot backdate its own
 * completion. Refuses a `withdrawn` row and refuses a second completion; see
 * EnrollmentNotCompletableException.
 *
 * THE AUTHORIZATION THAT BINDS IS THE LOCKING ONE
 * -----------------------------------------------
 * Same reasoning as WithdrawEnrollmentAction: CompletionRule is asked again
 * with locking: true, after EnrollmentMutex has taken the batch mutex, and
 * THAT answer is final. A denial is thrown directly from it.
 *
 * BATCH BEFORE ENROLMENT, ALWAYS
 * -------------------------------
 * EnrollmentMutex enforces the same global lock order EnrollStudentAction
 * established. A consistent order is what stops this Action deadlocking
 * against the others under concurrency.
 *
 * THE ACTIVITY ENTRY IS AUTOMATIC, BUT THE CAUSER IS NOT
 * ---------------------------------------------------------
 * `status` and `completed_at` are both in Enrollment::auditedAttributes(), so
 * RecordsActivity's ordinary model-event logging already puts the before/after
 * diff in the log without this Action lifting a finger — unlike
 * ReverseEnrollmentCompletionAction, whose mandatory reason has no column to
 * live in and so has to build its own entry.
 *
 * What the automatic path does NOT get for free is the actor. Spatie resolves
 * the causer from the authenticated session, while this Action authorizes and
 * writes against the actor it was PASSED — and the two are not the same thing
 * for a console invocation, a queued job, or a request made while somebody
 * else's session happens to be active. So the update runs inside
 * CauserResolver::withCauser(), exactly as WriteOffChargeAction does it.
 */
final class CompleteEnrollmentAction
{
    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly CompletionRule $rule,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @throws EnrollmentNotCompletableException if the enrolment is withdrawn
     *                                           or already completed.
     * @throws EnrollmentBatchChangedException if the enrolment moved batches.
     * @throws AuthorizationException if the actor may not mark completion on
     *                                this batch.
     */
    public function execute(User $actor, Enrollment $enrollment): Enrollment
    {
        return DB::transaction(function () use ($actor, $enrollment): Enrollment {
            $held = $this->mutex->acquire($enrollment);

            $this->authorize($actor, $held->batchId);

            $locked = $held->enrollment;

            if ($locked->status !== EnrollmentStatus::Active) {
                throw new EnrollmentNotCompletableException((int) $locked->getKey());
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $locked->update([
                    'status' => EnrollmentStatus::Completed,
                    'completed_at' => now(),
                ]),
            );

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
