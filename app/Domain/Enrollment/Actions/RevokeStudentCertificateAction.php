<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Exceptions\CertificateChangedException;
use App\Domain\Enrollment\Exceptions\NoValidCertificateException;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\EnrollmentMutex;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Withdraw a certificate that should no longer stand (design section 6.4) —
 * T5's third transition.
 *
 * VALID -> REVOKED, WITH A MANDATORY REASON
 * --------------------------------------------
 * Requires a `valid` certificate and a non-blank reason.
 * `chk_student_certificates_revocation` (T4) is the database's own version of
 * this requirement — actor, time and reason arrive together or not at all —
 * so this Action supplies all three in the same update rather than leaving
 * any of them to a later step.
 *
 * WHY THE REASON IS VALIDATED HERE, NOT IN A DEDICATED DATA CLASS
 * ---------------------------------------------------------------------
 * The same call ReverseEnrollmentCompletionAction makes, for the same reason:
 * this task's file scope carries no Data object for revocation, so the guard
 * sits at the top of execute() instead of behind a class that would exist
 * only to hold one string. WriteOffChargeData and AdjustChargeData validate a
 * reason in their own constructors because a blank one is a property of the
 * VALUE; here there is no value object, so the Action itself is where that
 * property is enforced for a hand-built call. The Filament form's
 * `->required()` is the user-facing guard for anyone reaching this through
 * the panel — this is the backstop for a direct caller.
 *
 * NO UNIQUE-INDEX RETRY LOGIC HERE, UNLIKE ISSUE AND REPLACE
 * -----------------------------------------------------------------
 * Revoking never inserts a row and never changes `enrollment_id`, so it
 * cannot collide on either `student_certificates_reference_number_unique` or
 * `uniq_valid_certificate_per_enrollment` — a revoked row's generated
 * `valid_enrollment_id` becomes NULL, which never collides with anything.
 *
 * THE SAME LOCK ORDER AS ISSUE AND REPLACE
 * --------------------------------------------
 * EnrollmentMutex's enrolment lock, then a LOCKING read of the current valid
 * certificate — see IssueStudentCertificateAction's docblock for why the read
 * must be locking rather than ordinary.
 */
final class RevokeStudentCertificateAction
{
    public function __construct(
        private readonly EnrollmentMutex $mutex,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @throws NoValidCertificateException if no valid certificate stands to revoke.
     * @throws CertificateChangedException if \$expectedCertificateId is no longer the current valid row.
     * @throws AuthorizationException if the actor may not revoke certificates.
     * @throws InvalidArgumentException if the reason is blank after trimming.
     */
    public function execute(User $actor, Enrollment $enrollment, string $reason, ?int $expectedCertificateId = null): StudentCertificate
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Revoking a certificate requires a reason.');
        }

        Gate::forUser($actor)->authorize('revoke', StudentCertificate::class);

        return DB::transaction(function () use ($actor, $enrollment, $reason, $expectedCertificateId): StudentCertificate {
            $held = $this->mutex->acquire($enrollment);
            $locked = $held->enrollment;

            $current = $this->lockCurrentValidCertificate($locked);

            if ($current === null) {
                throw new NoValidCertificateException((int) $locked->getKey());
            }

            /*
             * THE ROW THE ACTOR CLICKED, RE-CHECKED UNDER THE LOCK.
             *
             * $expectedCertificateId is null for callers that genuinely mean
             * "whatever is current". The panel is not one of them: its row
             * actions are clicked on a SPECIFIC certificate, and between the
             * modal opening and the actor submitting it, somebody else can
             * replace that row — leaving this Action to resolve a DIFFERENT
             * certificate and act on it under the first actor's intent. See
             * CertificateChangedException.
             *
             * Checked here, inside the transaction and after the locking read,
             * because anywhere earlier is a snapshot answer to a question that
             * only the lock can settle.
             */
            if ($expectedCertificateId !== null && (int) $current->getKey() !== $expectedCertificateId) {
                throw new CertificateChangedException(
                    (int) $locked->getKey(),
                    $expectedCertificateId,
                    (int) $current->getKey(),
                );
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $current->update([
                    'status' => CertificateStatus::Revoked,
                    'revoked_at' => now(),
                    'revoked_by' => $actor->getKey(),
                    // Stored verbatim, not trimmed — the same convention
                    // WriteOffChargeData and ReverseEnrollmentCompletionAction
                    // use: trim() decides whether the reason is acceptable,
                    // it does not rewrite what was typed.
                    'revocation_reason' => $reason,
                ]),
            );

            return $current;
        });
    }

    /** See ReplaceStudentCertificateAction's identical method. */
    private function lockCurrentValidCertificate(Enrollment $enrollment): ?StudentCertificate
    {
        return StudentCertificate::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('status', CertificateStatus::Valid)
            ->lockForUpdate()
            ->first();
    }
}
