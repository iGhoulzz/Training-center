<?php

declare(strict_types=1);

namespace Tests\Feature\Staff\Support;

use App\Domain\Staff\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A UserPolicy that no longer protects any super admin (P4-T43).
 *
 * Guard 5 means no actor can reach a super admin through the Actions, so the
 * last-super-admin invariant (guard 3) is never the first thing to refuse. The
 * invariant is still the second line of defence, and a later change to the
 * policy must not be able to make it dead: these tests install this policy to
 * prove the Actions still hand the write to SuperAdminInvariantService.
 *
 * It keeps the permission and self rules - only the rank refusal is removed.
 */
final class PermissiveUserPolicy extends UserPolicy
{
    /**
     * Replace the registered policy for the rest of the test.
     */
    public static function install(): void
    {
        Gate::policy(User::class, self::class);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('update_user');
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('delete_user') && ! $actor->is($target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->can('reset_user_password') && ! $actor->is($target);
    }
}
