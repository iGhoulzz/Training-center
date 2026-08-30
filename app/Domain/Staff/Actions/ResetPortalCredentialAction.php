<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Exceptions\ProtectedAccountException;
use App\Domain\Staff\Exceptions\StudentHasNoPortalAccountException;
use App\Domain\Staff\Support\ActivityEvent;
use App\Domain\Staff\Support\TemporaryPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Resets a credential only through the student-account relationship.
 */
final class ResetPortalCredentialAction
{
    public function __construct(
        private readonly TemporaryPassword $temporaryPassword,
    ) {}

    /**
     * @throws ProtectedAccountException
     * @throws StudentHasNoPortalAccountException
     */
    public function execute(User $actor, Student $student): string
    {
        Gate::forUser($actor)->authorize('reset_portal_credential');

        return DB::transaction(function () use ($actor, $student): string {
            $lockedStudent = Student::query()
                ->lockForUpdate()
                ->findOrFail($student->getKey());

            if ($lockedStudent->user_id === null) {
                throw new StudentHasNoPortalAccountException;
            }

            $account = User::withTrashed()
                ->lockForUpdate()
                ->find($lockedStudent->user_id);

            if (! $account instanceof User || $account->trashed()) {
                throw new StudentHasNoPortalAccountException;
            }

            if ($account->can('access_admin_panel')) {
                throw new ProtectedAccountException;
            }

            $plain = $this->temporaryPassword->issue($account);

            activity()
                ->causedBy($actor)
                ->performedOn($account)
                ->event(ActivityEvent::PASSWORD_RESET)
                ->log(ActivityEvent::PASSWORD_RESET);

            return $plain;
        });
    }
}
