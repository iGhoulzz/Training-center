<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll run: draft, then finalized, then immutable.
 *
 * Configuration only — casts, three relations, two scopes, one derived
 * predicate. No business logic and no write guards; see App\Models\User for why
 * that architecture was removed in P1-T04c.
 *
 * FINALIZED MEANS POSTED, NOT PAID
 * --------------------------------
 * Finalizing approves a run and posts it to the books. **It does not disburse
 * money.** Payroll disbursement is out of scope for the entire project, there is
 * no paid/unpaid flag here and none should be added, and wage-cost reports count
 * finalized lines as cost incurred (design section 7). Anyone reading
 * "finalized" as "the staff have their money" is reading it wrong, and this class
 * is where that would start.
 *
 * THERE IS NO STATUS COLUMN
 * -------------------------
 * Draft versus finalized derives from `finalized_at`, the same rule design
 * section 4 sets for charges and design section 11 enforces across the whole
 * Finance domain. isFinalized() reads the fact column; it does not cache one.
 *
 * THE PERIOD BELONGS TO ONE RUN TYPE, AND THE DATABASE DECIDES THAT
 * ----------------------------------------------------------------
 * `payroll_runs_period_matches_type` requires a `monthly_salary` run to carry
 * both period dates and the other two types to carry neither, and it enumerates
 * the three literals rather than negating one — so a fourth type is refused
 * outright until somebody decides its period rule. `PayrollRunType::hasPeriod()`
 * is the PHP side of that same rule and is where a caller should ask; nothing
 * here restates it, because a second copy is a second thing to keep in step.
 *
 * @property int $id
 */
#[Fillable([
    'type',
    'period_start',
    'period_end',
    'created_by',
    'finalized_at',
    'finalized_by',
    'notes',
])]
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The person who opened the run.
     *
     * withTrashed(), because users soft-delete while this foreign key restricts:
     * an account leaving must not turn a payroll run into one nobody appears to
     * have started.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * The person who approved and posted it, if anyone has.
     *
     * withTrashed(), for the same reason as createdBy(). Null exactly while
     * `finalized_at` is null — `payroll_runs_finalization_columns_paired` refuses
     * an approval with no approver and an approver with no time, so the two
     * cannot disagree.
     *
     * @return BelongsTo<User, $this>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by')->withTrashed();
    }

    /**
     * Every line this run pays.
     *
     * NOT A WRITE PATH. Lines are built by `CreatePayrollRunAction` and sealed by
     * `FinalizePayrollRunAction`, which inside one transaction verifies that each
     * line's shape matches this run's type — the check no database constraint can
     * make, because a MySQL `CHECK` may not reference another table — and takes
     * the deterministic ascending user-id locks design section 7 requires. A bare
     * `$run->lines()->create()` reaches around both.
     *
     * This is the one financial relation whose foreign key cascades, and it is
     * correct: a line whose run does not exist is not a fact about anything, and
     * `PayrollRunPolicy` refuses to delete a finalized run regardless of
     * `delete_payroll_run`, so the cascade can only ever discard draft lines.
     *
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * Limit a query to runs that have been posted to the books.
     *
     * What every wage-cost report counts. Written as a scope rather than left to
     * each caller because the rule — cost is incurred at finalization, not at
     * draft — is one rule, and a report that forgets it counts a draft nobody
     * approved.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFinalized(Builder $query): void
    {
        $query->whereNotNull('finalized_at');
    }

    /**
     * Limit a query to runs still open for editing.
     *
     * The complement of finalized(), and the only runs that are deletable or that
     * may take an adjustment.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDraft(Builder $query): void
    {
        $query->whereNull('finalized_at');
    }

    /**
     * Has this run been posted?
     *
     * Derived from the fact column, never stored. Posted, not paid — see the
     * class docblock.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** What the run covers, who opened it, and who approved it. */
    public function auditedAttributes(): array
    {
        return [
            'type',
            'period_start',
            'period_end',
            'created_by',
            'finalized_at',
            'finalized_by',
            'notes',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PayrollRunType::class,

            /*
             * Dates, not datetimes. A period is a local calendar range that the
             * salary segmentation intersects with calendar months, so there is
             * no instant to convert and nothing here reads a timezone.
             */
            'period_start' => 'date',
            'period_end' => 'date',

            // A datetime, because this one is an instant: the moment a named
            // person approved the run.
            'finalized_at' => 'datetime',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }
}
