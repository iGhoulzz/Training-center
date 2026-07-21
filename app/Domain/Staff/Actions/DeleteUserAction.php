<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\User;
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
        Gate::forUser($actor)->authorize('delete', $target);

        $delete = fn () => $target->delete();

        if ($target->isSuperAdmin()) {
            $this->invariant->protect($delete);

            return;
        }

        $delete();
    }
}
