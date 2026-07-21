<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\User;
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
        Gate::forUser($actor)->authorize('update', $target);

        if (! $target->is_active) {
            return;
        }

        $deactivate = fn () => $target->update(['is_active' => false]);

        if ($target->isSuperAdmin()) {
            $this->invariant->protect($deactivate);

            return;
        }

        $deactivate();
    }
}
