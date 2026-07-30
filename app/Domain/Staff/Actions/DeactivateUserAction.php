<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deactivates a staff account (sets is_active = false).
 *
 * Authorizes via UserPolicy::update — which requires the update_user ability
 * and refuses a non-super-admin acting on a super admin (guard 1) — then, when
 * the target is a super admin, routes the write through
 * SuperAdminInvariantService so deactivating the last active super admin is
 * refused atomically (guard 3, 422).
 */
final class DeactivateUserAction
{
    public function __construct(
        private readonly SuperAdminInvariantService $invariant,
    ) {}

    public function execute(User $actor, User $target): void
    {
        /*
         * LOCK FIRST, DECIDE SECOND (P1-T15, security finding 4).
         *
         * See DeleteUserAction for the full reasoning; this Action had the
         * identical defect. The super-admin branch was chosen from an unlocked
         * read of the caller's instance, so a role grant committing in the gap
         * sent a super admin down the unprotected path and the last-super-admin
         * invariant never ran.
         *
         * The role row is locked before the user row, unconditionally, matching
         * the one global order SyncUserRolesAction documents.
         */
        DB::transaction(function () use ($actor, $target): void {
            Role::lockSuperAdminRow();

            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            Gate::forUser($actor)->authorize('update', $lockedUser);

            // The idempotent no-op is decided under the lock too. Read from the
            // caller's instance, it could skip a deactivation that a concurrent
            // reactivation had just made necessary again.
            if (! $lockedUser->is_active) {
                return;
            }

            $deactivate = fn () => $lockedUser->update(['is_active' => false]);

            if ($lockedUser->isSuperAdmin()) {
                $this->invariant->protect($deactivate);

                return;
            }

            $deactivate();
        });
    }
}
