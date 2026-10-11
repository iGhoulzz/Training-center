<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Rename a category, or switch it on or off.
 *
 * DEACTIVATION IS AN UPDATE, AND THERE IS NO DELETE
 * -------------------------------------------------
 * `is_active` is the one lifecycle column, and moving it takes
 * `update_expense_category` like any other edit (spec section 5: categories are
 * viewed, created, edited and deactivated). A deactivated category leaves the
 * picker and stays on every expense that already names it. No Action deletes a
 * category.
 *
 * A LOST RACE ON THE NAME IS A FIELD ERROR, NEVER A 500
 * -----------------------------------------------------
 * Renaming into a name another save has just taken passes the validator's read and
 * collides on `expense_categories_name_en_unique`. The violation is turned into the
 * same `name_en` error the validator gives, by the helper the create Action uses.
 *
 * THE ROW IS LOCKED BEFORE IT IS WRITTEN
 * --------------------------------------
 * `RecordExpenseAction` takes the same lock on the category it records against,
 * so a deactivation and an expense being recorded serialise: either the expense
 * is recorded first, against a category that was active at that moment, or the
 * category is switched off first and the expense is refused. Neither can read a
 * stale "still active".
 */
final class UpdateExpenseCategoryAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(
        User $actor,
        ExpenseCategory $category,
        string $nameEn,
        ?string $nameAr,
        bool $isActive,
    ): ExpenseCategory {
        Gate::forUser($actor)->authorize('update', $category);

        $validated = Validator::make(
            CreateExpenseCategoryAction::normalise($nameEn, $nameAr),
            CreateExpenseCategoryAction::rules((int) $category->getKey()),
            [],
            CreateExpenseCategoryAction::attributeNames(),
        )->validate();

        try {
            return DB::transaction(function () use ($actor, $category, $validated, $isActive): ExpenseCategory {
                $lockedCategory = ExpenseCategory::query()
                    ->lockForUpdate()
                    ->findOrFail($category->getKey());

                $this->causers->withCauser(
                    $actor,
                    fn (): bool => $lockedCategory->update([
                        'name_en' => (string) $validated['name_en'],
                        'name_ar' => $validated['name_ar'] ?? null,
                        'is_active' => $isActive,
                    ]),
                );

                return $lockedCategory;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw CreateExpenseCategoryAction::duplicateNameRefusal($exception);
        }
    }
}
