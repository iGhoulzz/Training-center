<?php

declare(strict_types=1);

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;

/**
 * Authorization for the expense category list (spec section 5, phase 4 rows).
 *
 * FOUR ABILITIES, AND NO DELETE PATH
 * ----------------------------------
 * A category is listed, viewed, created and edited. Deactivating one is an edit:
 * it moves `is_active`, which is why there is no separate deactivate ability.
 * `delete()` and every bulk or soft-delete form refuse for every actor, super
 * admin included, because a category leaves the picker and stays on its history.
 * The delete ability name is deliberately NEVER SEEDED, and the resource test
 * grants it anyway and proves the refusal still stands.
 *
 * Seeded to super admin and admin. Staff hold none of it.
 *
 * PERMISSION-BASED, NEVER ROLE-BASED. Every method asks can(); which roles hold
 * which ability is `RolePermissionSeeder`'s business.
 *
 * deleteAny(), restore(), restoreAny(), forceDelete(), forceDeleteAny(),
 * replicate() AND reorder() ARE WRITTEN OUT, because Filament reads an unstated
 * ability as ALLOW while the Gate reads it as a refusal. See `ExpensePolicy`.
 * A bulk action is authorized once against its `*Any` method, so those refuse for
 * everyone.
 *
 * NOT REGISTERED IN AppServiceProvider: convention-based discovery resolves it.
 */
final class ExpenseCategoryPolicy
{
    /** May this actor list the categories? */
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_expense_category');
    }

    /** May this actor open one category? */
    public function view(User $actor, ExpenseCategory $category): bool
    {
        return $actor->can('view_expense_category');
    }

    /** May this actor add a category? */
    public function create(User $actor): bool
    {
        return $actor->can('create_expense_category');
    }

    /** May this actor rename a category, or switch it on or off? */
    public function update(User $actor, ExpenseCategory $category): bool
    {
        return $actor->can('update_expense_category');
    }

    /** Refused — a category is deactivated, never deleted. */
    public function delete(User $actor, ExpenseCategory $category): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities
    |--------------------------------------------------------------------------
    |
    | NOT REDUNDANT. See the class docblock: an unstated ability is ALLOWED by
    | Filament, so each is written out as a refusal.
    */

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, ExpenseCategory $category): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, ExpenseCategory $category): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, ExpenseCategory $category): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }
}
