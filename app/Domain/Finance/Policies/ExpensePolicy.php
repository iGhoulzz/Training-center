<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Expense;
use App\Models\User;

/**
 * Authorization for expenses (spec section 5, phase 4 rows).
 *
 * FOUR ABILITIES, AND NO EDIT OR DELETE PATH
 * ------------------------------------------
 * An expense is recorded, read and — if it was wrong — reversed. It is never
 * edited and never deleted. `update()` and `delete()` return false
 * unconditionally, and the two ability names those methods would otherwise ask
 * about are deliberately NEVER SEEDED: seeding an ability nothing honours invites
 * somebody to wire it up later. The resource test grants each of them anyway and
 * proves the refusal still stands, which is a stronger statement than "the
 * permission does not exist".
 *
 * Reading and recording are seeded to super admin and admin. Reversal is super
 * admin alone, mirroring `reverse_payment`: it is the restricted capability that
 * keeps the audit trail meaningful. Staff hold none of it.
 *
 * reverse() IS ABOUT THE ACTOR, NOT THE ROW
 * -----------------------------------------
 * Record-independent, exactly like `PaymentPolicy::reverse()`. Whether this
 * particular expense already carries a reversal is a question about the ROW, and
 * a policy cannot answer it truthfully: `ReverseExpenseAction` decides it on a
 * fresh locking read, after the lock is held. Folding a check of the caller's
 * possibly stale model in here would be the one place a second tab slips through.
 *
 * deleteAny(), restore(), restoreAny(), forceDelete(), forceDeleteAny(),
 * replicate() AND reorder() ARE WRITTEN OUT, NOT LEFT UNSTATED
 * ------------------------------------------------------------------------
 * Filament reads an UNSTATED ability as ALLOW while the Gate reads it as a
 * refusal, so a method this policy did not mention would be denied everywhere a
 * test looked and permitted the instant somebody rendered the standard control
 * for it. `PolicyAbilitySurfaceTest` fails the build on an omission. A bulk action
 * is authorized once against its `*Any` method and never consults the per-record
 * rule, so those refuse here for everyone.
 *
 * NOT REGISTERED IN AppServiceProvider. `Expense` lives at
 * `App\Domain\Finance\Models\Expense`, and Laravel's convention-based discovery
 * resolves it to this class unaided, as it does every other Finance policy.
 */
final class ExpensePolicy
{
    /** May this actor list expenses? */
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_expense');
    }

    /** May this actor open one expense? */
    public function view(User $actor, Expense $expense): bool
    {
        return $actor->can('view_expense');
    }

    /** May this actor record money paid out? */
    public function create(User $actor): bool
    {
        return $actor->can('create_expense');
    }

    /** Refused — a wrong expense is reversed and the correct figure recorded anew. */
    public function update(User $actor, Expense $expense): bool
    {
        return false;
    }

    /** Refused — an expense is never deleted, only reversed. */
    public function delete(User $actor, Expense $expense): bool
    {
        return false;
    }

    /** May this actor undo an expense? Super admin alone. See the class docblock. */
    public function reverse(User $actor): bool
    {
        return $actor->can('reverse_expense');
    }

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities
    |--------------------------------------------------------------------------
    |
    | THESE ARE NOT REDUNDANT AND MUST NOT BE DELETED AS DEAD CODE. See the
    | class docblock: an ability this policy simply did not mention would be
    | DENIED by the Gate and ALLOWED by Filament.
    */

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Expense $expense): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Expense $expense): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, Expense $expense): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
