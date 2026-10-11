<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the centre paid out other than wages.
 *
 * Configuration only — casts, three relations, one scope, one derived predicate.
 * No business logic and no write guards; see App\Models\User for why that
 * architecture was removed in P1-T04c.
 *
 * AN EXPENSE IS NEVER EDITED AND NEVER DELETED
 * --------------------------------------------
 * `amount`, `paid_on`, `expense_category_id` and `description` are written once,
 * by `RecordExpenseAction`, and have no write path afterwards. A wrong expense is
 * reversed — `reversed_at`, `reversed_by` and `reversal_reason` are written
 * together by `ReverseExpenseAction` under `reverse_expense` and never unset —
 * and the correct figure is recorded as a new row. `ExpensePolicy::update()` and
 * `delete()` return false unconditionally.
 *
 * `paid_on` IS A CALENDAR DATE, NOT AN INSTANT
 * --------------------------------------------
 * The date the money left, in the centre's own calendar. The `date` cast hands
 * back a Carbon for convenience; nothing may convert it between zones, and
 * `ExpenseReport` never does.
 *
 * NO TOTAL IS STORED
 * ------------------
 * `amount` is the only money column. A month's expenses are a SQL SUM() in
 * `ExpenseReport`, for the reason design section 6 gives for every amount in
 * this domain: summing `decimal:3` strings in PHP converts them to float.
 *
 * @property int $id
 */
#[Fillable([
    'expense_category_id',
    'amount',
    'paid_on',
    'description',
    'recorded_by',
    'reversed_at',
    'reversed_by',
    'reversal_reason',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * What this was spent on.
     *
     * Not `withTrashed()`: categories do not soft-delete, they are deactivated,
     * and a deactivated one resolves normally.
     *
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * The staff member who entered it.
     *
     * withTrashed(), because users soft-delete while this restricts: an account
     * leaving must not turn money into money nobody appears to have recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /**
     * The super admin who reversed it, if anyone has. withTrashed() for the same
     * reason as recordedBy().
     *
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }

    /**
     * Limit a query to expenses that still count as money paid out.
     *
     * `ExpenseReport` applies this, so a reversed expense is absent from every
     * profit figure through exactly one mechanism — the same shape as
     * `Payment::scopeNotReversed()` for revenue. A report that forgets it counts
     * reversed money again.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotReversed(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    /**
     * Has this expense been undone?
     *
     * Derived from the fact column, never stored. NOT a decision point for
     * `ReverseExpenseAction`, which decides on a fresh locking read: a model
     * loaded earlier may be stale by the time it is acted on.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * What happened, and who did it. Every column except the timestamps.
     *
     * The three reversal columns are audited, so the reason travels into the
     * append-only log with the actor the Action names.
     */
    public function auditedAttributes(): array
    {
        return [
            'expense_category_id',
            'amount',
            'paid_on',
            'description',
            'recorded_by',
            'reversed_at',
            'reversed_by',
            'reversal_reason',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:3 on the money column. Never float, never two places — and
            // never summed in PHP; see the class docblock.
            'amount' => 'decimal:3',
            'paid_on' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }
}
