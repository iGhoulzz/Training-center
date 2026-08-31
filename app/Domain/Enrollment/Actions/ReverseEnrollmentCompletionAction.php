<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\CompletionNotReversibleException;
use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\CompletionRule;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Undo a completion that was marked in error — completed -> active, with a
 * reason that goes on the record.
 *
 * THE SAME PAIR CompleteEnrollmentAction USES, PLUS A MANDATORY REASON
 * -----------------------------------------------------------------------
 * EnrollmentMutex, then CompletionRule asked again with locking: true after
 * the mutex is held. Spec section 5.1: reversal uses the identical
 * authorization pair as completion, so the assigned instructor who mis-marked
 * a row can correct their own mistake without escalating to an admin — the
 * same actor who could complete it can reverse it.
 *
 * WHY THE REASON IS VALIDATED HERE, NOT IN A DEDICATED DATA CLASS
 * ---------------------------------------------------------------------
 * WriteOffChargeData and AdjustChargeData validate their own reason in their
 * constructors, because a blank reason is a property of the VALUE rather than
 * of the operation. This task's file scope carries no equivalent Data object
 * for reversal — there is no form in scope to build one for — so the same
 * guard sits at the top of execute() instead of behind a second class that
 * would exist only to hold one string.
 *
 * THE REASON HAS NOWHERE TO LIVE EXCEPT THE ACTIVITY LOG
 * ------------------------------------------------------------
 * `enrollments` carries no reversal-reason column — unlike a write-off, there
 * is no `reversal_reason` field for RecordsActivity's automatic model-event
 * logging to pick up. So, exactly as AdjustChargeAction does for a charge
 * correction with no column of its own: disableLogging() suppresses the
 * automatic entry for this one instance, and this Action builds the one entry
 * that carries both the before/after diff and the reason, in the same call.
 * `ActivityEvent::UPDATED` is reused rather than a new event name minted for
 * this — this genuinely is an update to the enrolment row, correctly labelled
 * and already translated, and LocalizationTest requires every literal passed
 * to ->event()/->log() to come from the declared vocabulary.
 *
 * THE CERTIFICATE ROWS ARE LOCKED AFTER THE ENROLMENT
 * -----------------------------------------------------
 * Preserving the global batch -> enrolment order EnrollStudentAction
 * established, extended one link further: batch -> enrolment -> certificate.
 * Locking student_certificates AFTER the enrolment, inside the same
 * transaction, is what stops two concurrent reversal attempts from both
 * observing "no valid certificate" and both proceeding — the identical
 * anti-race reasoning design section 6.4 gives for T5's certificate Actions,
 * applied here because this Action is the other write that touches the same
 * invariant (at most one valid certificate implies a completed enrolment
 * behind it).
 *
 * THE REAL student_certificates TABLE, NOT A STAND-IN
 * -----------------------------------------------------
 * An earlier draft of the phase 3 plan had this task running before T4's
 * table existed and proposed a temporary interface this task would define and
 * T5 would rewire — see the plan's note under Task 3. T4 now ships first, so
 * this reads StudentCertificate directly and there is one check with one test,
 * not two that could quietly disagree.
 */
final class ReverseEnrollmentCompletionAction
{
    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly CompletionRule $rule,
    ) {}

    /**
     * @throws CompletionNotReversibleException if the enrolment is not
     *                                          completed, or a valid
     *                                          certificate still stands
     *                                          against it.
     * @throws EnrollmentBatchChangedException if the enrolment moved batches.
     * @throws AuthorizationException if the actor may not amend this enrolment.
     */
    public function execute(User $actor, Enrollment $enrollment, string $reason): Enrollment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reversing a completion requires a reason.');
        }

        return DB::transaction(function () use ($actor, $enrollment, $reason): Enrollment {
            $held = $this->mutex->acquire($enrollment);

            $this->authorize($actor, $held->batchId);

            $locked = $held->enrollment;

            if ($locked->status !== EnrollmentStatus::Completed) {
                throw CompletionNotReversibleException::wrongStatus((int) $locked->getKey());
            }

            if ($this->hasValidCertificate($locked)) {
                throw CompletionNotReversibleException::certificateStillValid((int) $locked->getKey());
            }

            $this->reverse($actor, $locked, $reason);

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

    /**
     * A LOCKING existence check against the real register, taken after the
     * enrolment is already locked. Not a count, and not a relation load: both
     * would be answered from whatever the caller happened to have in memory or
     * from a snapshot read that predates this transaction's own locks.
     */
    private function hasValidCertificate(Enrollment $enrollment): bool
    {
        return StudentCertificate::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('status', CertificateStatus::Valid)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Write the transition and the one activity entry that carries both the
     * diff and the reason — see the class docblock for why this cannot be the
     * automatic model-event entry.
     */
    private function reverse(User $actor, Enrollment $locked, string $reason): void
    {
        $before = [
            'status' => $locked->status,
            'completed_at' => $locked->completed_at,
        ];

        $locked->disableLogging();

        $locked->update([
            'status' => EnrollmentStatus::Active,
            'completed_at' => null,
        ]);

        $locked->enableLogging();

        activity()
            ->causedBy($actor)
            ->performedOn($locked)
            ->event(ActivityEvent::UPDATED)
            ->withProperties(['reason' => $reason])
            ->withChanges([
                'attributes' => [
                    'status' => $locked->status,
                    'completed_at' => $locked->completed_at,
                ],
                'old' => $before,
            ])
            ->log(ActivityEvent::UPDATED);
    }
}
