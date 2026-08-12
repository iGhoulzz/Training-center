<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Discount;
use App\Models\User;

/** Authorization for immutable discount definitions. */
final class DiscountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_discount');
    }

    public function view(User $actor, Discount $discount): bool
    {
        return $actor->can('view_discount');
    }

    public function create(User $actor): bool
    {
        return $actor->can('manage_pricing');
    }

    public function update(User $actor, Discount $discount): bool
    {
        return false;
    }

    public function deactivate(User $actor, Discount $discount): bool
    {
        return $actor->can('manage_pricing');
    }

    public function delete(User $actor, Discount $discount): bool
    {
        return $actor->can('manage_pricing');
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Discount $discount): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Discount $discount): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, Discount $discount): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
