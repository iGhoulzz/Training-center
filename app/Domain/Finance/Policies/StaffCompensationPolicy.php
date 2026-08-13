<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;

/** Authorization for read-only history and Action-backed rate changes. */
final class StaffCompensationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_staff_compensation');
    }

    public function view(User $actor, StaffCompensation $rate): bool
    {
        return $actor->can('view_staff_compensation');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_staff_compensation');
    }

    public function update(User $actor, StaffCompensation $rate): bool
    {
        return false;
    }

    public function delete(User $actor, StaffCompensation $rate): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, StaffCompensation $rate): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, StaffCompensation $rate): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, StaffCompensation $rate): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
