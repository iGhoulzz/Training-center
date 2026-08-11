<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\PayrollLineAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bonus or a deduction applied to a payroll line **while its run is still a
 * draft**.
 *
 * Configuration only — casts and two relations. No business logic and no write
 * guards; see App\Models\User for why that architecture was removed in P1-T04c.
 *
 * THIS IS NOT THE CORRECTION MECHANISM, AND THE TWO ARE EASY TO CONFUSE
 * --------------------------------------------------------------------
 * There are two ways an amount moves, and they differ in *when*, not in kind
 * (design section 7):
 *
 *   - **Before the run is posted** — a row here. Something known in advance: a
 *     bonus the manager decided, a deduction for unpaid leave.
 *   - **After the run is finalized** — an `adjustment` run, whose lines carry
 *     `corrects_payroll_line_id` and post to the corrected line's period. A
 *     finalized run is immutable: no edits, no deletions, and **no new rows
 *     here**. Design revision 1 offered only this table, which does not solve a
 *     mistake discovered next month.
 *
 * "Adjustments only while draft" is a lock and a check inside the writing
 * transaction (design section 11), and deliberately not a guard in this class: a
 * MySQL `CHECK` cannot read `payroll_runs.finalized_at` through two tables, and a
 * PHP check here would run outside that lock and be reassuring rather than true.
 *
 * THE SIGN IS THE WHOLE MECHANISM
 * -------------------------------
 * A bonus and a deduction are the same operation with a different sign, not two
 * mechanisms — `amount` is `decimal(12, 3)` without `unsigned()` for that reason.
 * `payroll_line_adjustments_amount_not_zero` refuses zero rather than only
 * refusing negatives: an adjustment of nothing is a reason with no effect, and it
 * would still appear in the audit trail as though something had happened.
 *
 * @property int $id
 */
#[Fillable([
    'payroll_line_id',
    'amount',
    'reason',
    'created_by',
])]
class PayrollLineAdjustment extends Model
{
    /** @use HasFactory<PayrollLineAdjustmentFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The line this moves.
     *
     * No withTrashed(): payroll lines do not soft-delete. This foreign key
     * cascades, and that is correct — an adjustment to a line that no longer
     * exists is not a fact about anything, and the only line that can be deleted
     * is a draft one, because `payroll_lines.payroll_run_id` cascades from a run
     * that only a policy-approved *draft* deletion can remove.
     *
     * @return BelongsTo<PayrollLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /**
     * The person who decided it.
     *
     * withTrashed(), because users soft-delete while this foreign key restricts:
     * an account leaving must not turn a change to somebody's wage into one
     * nobody appears to have made — which is the entire reason the reason column
     * beside it is NOT NULL.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Which line, how much, why, and on whose say-so. */
    public function auditedAttributes(): array
    {
        return [
            'payroll_line_id',
            'amount',
            'reason',
            'created_by',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:3, SIGNED. Never float, never two places.
            'amount' => 'decimal:3',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): PayrollLineAdjustmentFactory
    {
        return PayrollLineAdjustmentFactory::new();
    }
}
