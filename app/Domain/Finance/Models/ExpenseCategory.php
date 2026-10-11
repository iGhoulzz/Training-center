<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One heading an expense is recorded against — "Rent", "Utilities", "Insurance".
 *
 * Configuration only — casts, one relation, one scope, one display helper. No
 * business logic and no write guards; see App\Models\User for why that
 * architecture was removed in P1-T04c.
 *
 * DEACTIVATED, NEVER DELETED
 * --------------------------
 * `is_active` is the one lifecycle column, moved by `UpdateExpenseCategoryAction`.
 * A deactivated category is no longer offered when a new expense is recorded and
 * stays on every expense that already carries it. `ExpenseCategoryPolicy` refuses
 * delete for every actor, and `expenses.expense_category_id` restricts as a
 * backstop for a path that reaches the database without the policy.
 */
#[Fillable([
    'name_en',
    'name_ar',
    'is_active',
])]
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Every expense recorded against this category, reversed ones included.
     *
     * NOT A WRITE PATH. An expense is written by `RecordExpenseAction` and
     * reversed by `ReverseExpenseAction`; a bare `$category->expenses()->create()`
     * reaches around both, and the category's own `is_active` check with it.
     *
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Limit a query to the categories a new expense may be recorded against.
     *
     * The expense form's picker renders through this. Deactivated categories stay
     * queryable without it, because every expense that already names one still
     * has to resolve it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The name in the reader's language.
     *
     * Arabic wins only when the locale is Arabic AND a translation exists, so a
     * category added before anyone translated it still has a usable name. A method
     * rather than an accessor for the reason Course::name() is one: the value
     * depends on the request locale, not on the row.
     */
    public function name(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar)
            ? (string) $this->name_ar
            : (string) $this->name_en;
    }

    /** Both name columns and the lifecycle flag — everything a person decides. */
    public function auditedAttributes(): array
    {
        return [
            'name_en',
            'name_ar',
            'is_active',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): ExpenseCategoryFactory
    {
        return ExpenseCategoryFactory::new();
    }
}
