<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;

/** Authorization for Action-backed payroll drafts and immutable posted runs. */
final class PayrollRunPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_payroll_run');
    }

    public function view(User $actor, PayrollRun $run): bool
    {
        return $actor->can('view_payroll_run');
    }

    public function create(User $actor): bool
    {
        return $actor->can('run_payroll');
    }

    public function finalize(User $actor, PayrollRun $run): bool
    {
        return $actor->can('finalize_payroll');
    }

    public function update(User $actor, PayrollRun $run): bool
    {
        return false;
    }

    public function delete(User $actor, PayrollRun $run): bool
    {
        return ! $run->isFinalized() && $actor->can('delete_payroll_run');
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, PayrollRun $run): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, PayrollRun $run): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, PayrollRun $run): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
