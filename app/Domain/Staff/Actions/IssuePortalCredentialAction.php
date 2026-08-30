<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Exceptions\EmailAlreadyRegisteredException;
use App\Domain\Staff\Exceptions\StudentHasNoEmailException;
use App\Domain\Staff\Exceptions\StudentHasPortalAccountException;
use App\Domain\Staff\Support\TemporaryPassword;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates the only kind of account a portal-credential issuer may create.
 */
final class IssuePortalCredentialAction
{
    /** MySQL ER_DUP_ENTRY. */
    private const DUPLICATE_ENTRY = 1062;

    /** The database backstop for one user account per email address. */
    private const EMAIL_UNIQUE_INDEX = 'users_email_unique';

    public function __construct(
        private readonly TemporaryPassword $temporaryPassword,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @throws EmailAlreadyRegisteredException
     * @throws StudentHasNoEmailException
     * @throws StudentHasPortalAccountException
     */
    public function execute(User $actor, Student $student): string
    {
        Gate::forUser($actor)->authorize('issue_portal_credential');

        try {
            return DB::transaction(function () use ($actor, $student): string {
                return $this->causers->withCauser($actor, function () use ($student): string {
                    $lockedStudent = Student::query()
                        ->lockForUpdate()
                        ->findOrFail($student->getKey());

                    if ($lockedStudent->user_id !== null) {
                        throw new StudentHasPortalAccountException;
                    }

                    if (blank($lockedStudent->email)) {
                        throw new StudentHasNoEmailException;
                    }

                    if (User::withTrashed()->where('email', $lockedStudent->email)->exists()) {
                        throw new EmailAlreadyRegisteredException;
                    }

                    $account = User::query()->create([
                        'name' => $lockedStudent->full_name,
                        'email' => $lockedStudent->email,
                        'password' => Str::password(32),
                    ]);

                    // This is intentionally literal: callers receive only a student
                    // account, never a way to select a staff-facing role.
                    $account->assignRole('student');

                    $lockedStudent->forceFill(['user_id' => $account->getKey()])->save();

                    return $this->temporaryPassword->issue($account);
                });
            });
        } catch (UniqueConstraintViolationException $exception) {
            $isEmailCollision = ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
                && $exception->index === self::EMAIL_UNIQUE_INDEX;

            if (! $isEmailCollision) {
                throw $exception;
            }

            throw new EmailAlreadyRegisteredException;
        }
    }
}
