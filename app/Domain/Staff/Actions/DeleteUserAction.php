<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Soft-deletes a staff account.
 *
 * Authorizes via UserPolicy::delete — which requires the delete_user ability,
 * refuses self-deletion (guard 2), and refuses a non-super-admin acting on a
 * super admin (guard 1) — then, when the target is a super admin, routes the
 * write through SuperAdminInvariantService so deleting the last active super
 * admin is refused atomically (guard 3, 422).
 */
final class DeleteUserAction
{
    public function __construct(
        private readonly SuperAdminInvariantService $invariant,
    ) {}

    public function execute(User $actor, User $target): void
    {
        /*
         * LOCK FIRST, DECIDE SECOND (P1-T15, security finding 4).
         *
         * The branch below asks whether the target is a super admin, and that
         * answer used to come from the instance the caller passed — read
         * outside any transaction and outside any lock. A concurrent role grant
         * lands in that gap: request A reads "ordinary account" and is
         * descheduled, request B makes that account a super admin, request C
         * deletes the only other super admin and passes its own invariant check
         * because A's target now counts as a survivor, then A resumes and
         * deletes it. Zero active super admins remain — a state
         * SuperAdminInvariantService calls "unrecoverable through the
         * application".
         *
         * SyncUserRolesAction already states the rule this now follows: the
         * super-admin role row is locked BEFORE the user row, always, and
         * unconditionally, "because whether it IS involved cannot be known
         * until the current roles are read — and that read is what the lock
         * protects". Locking only when isSuperAdmin() is already true asks the
         * very question the lock exists to make safe.
         *
         * The lock is retaken inside SuperAdminInvariantService::protect(); a
         * second acquisition within this transaction is a no-op, and the global
         * order is identical on either path.
         */
        DB::transaction(function () use ($actor, $target): void {
            Role::lockSuperAdminRow();

            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            // Authorized against the LOCKED row, never the caller's instance:
            // rank may have changed since that instance was loaded.
            Gate::forUser($actor)->authorize('delete', $lockedUser);

            $delete = fn () => $lockedUser->delete();

            if ($lockedUser->isSuperAdmin()) {
                $this->invariant->protect($delete);

                return;
            }

            $delete();
        });
    }
}
