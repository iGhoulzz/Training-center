<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Support\ActivityEvent;
use App\Domain\Staff\Support\TemporaryPassword;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * TRUSTED SERVER-CONSOLE PATH - NO ACTOR, NO AUTHORIZATION (P4-T43).
 * ===================================================================
 * The recovery for the rare real case: every administrator is locked out of the
 * panel (a lost one-time password, a deleted account), so there is no signed-in
 * principal left to authorize anything. Someone with a shell on the server
 * (`sudo -u www-data php artisan staff:issue-temporary-password` or
 * `staff:restore-user`, docs/DEPLOYMENT.md "Locked out of the admin") is the
 * principal, and holding that shell is the authorization.
 *
 * It is the sibling of SystemRoleWriter and shares its rules. The request-path
 * Actions (ResetUserPasswordAction, DeleteUserAction, ...) all take a `User
 * $actor` and authorize through UserPolicy; this class takes none, because the
 * policy has nobody to ask. The architecture tests (ActionBoundaryArchTest) allow
 * it to be referenced ONLY from the two console commands that exist for it, so
 * it cannot be reached from a controller, a Filament page, a Livewire component
 * or a job running on behalf of a user.
 *
 * NOT SILENT. Every write is recorded, with no causer (causedByAnonymous, for the
 * reason SystemRoleWriter::recordSystemEvent documents: a console run inside a
 * shell that happens to hold a login must never name a person who did not do
 * this) and a `source` property marking the server console, so the audit trail
 * can tell it from an administrator's reset in the panel.
 *
 * It does not touch roles, so it needs neither the last-super-admin invariant
 * nor the super-admin role lock: restoring an account only adds to the
 * population of super admins and a password change reduces nothing.
 *
 * DO NOT call this from application request code. Use the Actions there.
 */
final class ConsoleAccountRecovery
{
    /** The value of the `source` activity property on every entry written here. */
    public const SOURCE = 'console';

    public function __construct(
        private readonly TemporaryPassword $temporaryPassword,
    ) {}

    /**
     * Issue a temporary password and force a change at the next sign-in.
     *
     * @return string|null The plaintext password, to be shown once; null when
     *                     the account is soft-deleted (restore it first) or gone.
     */
    public function issueTemporaryPassword(User $account): ?string
    {
        return DB::transaction(function () use ($account): ?string {
            // The live-only query: a soft-deleted row is not found, and the
            // decision is made on the locked row rather than the caller's.
            $live = User::query()->lockForUpdate()->find($account->getKey());

            if ($live === null) {
                return null;
            }

            $plain = $this->temporaryPassword->issue($live);

            // Recorded explicitly, because the hash is not audited and the diff
            // may be empty (see ResetUserPasswordAction). No password, no hash.
            self::recordConsoleEvent($live, ActivityEvent::PASSWORD_RESET);

            return $plain;
        });
    }

    /**
     * Restore a soft-deleted account. Its roles are untouched: a soft delete
     * removes the row's visibility, not its model_has_roles rows.
     *
     * @return bool False when the account was not deleted (nothing was written).
     */
    public function restore(User $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            $deleted = User::onlyTrashed()->lockForUpdate()->find($account->getKey());

            if ($deleted === null) {
                return false;
            }

            $deleted->restore();

            self::recordConsoleEvent($deleted, ActivityEvent::RESTORED);

            return true;
        });
    }

    private static function recordConsoleEvent(Model $subject, string $event): void
    {
        activity()
            ->causedByAnonymous()
            ->performedOn($subject)
            ->event($event)
            ->withProperties(['source' => self::SOURCE])
            ->log($event);
    }
}
