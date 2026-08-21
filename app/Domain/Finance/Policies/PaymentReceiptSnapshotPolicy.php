<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Models\User;

/** Snapshots are internal document inputs; access authorizes the payment. */
final class PaymentReceiptSnapshotPolicy
{
    public function viewAny(User $actor): bool
    {
        return false;
    }

    public function view(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, PaymentReceiptSnapshot $snapshot): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
