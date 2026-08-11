<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The bill. One per enrolment, raised as part of enrolling, payable in parts.
 *
 * Configuration only — casts, four relations, one derived predicate. No business
 * logic and no write guards; see App\Models\User for why that architecture was
 * removed in P1-T04c.
 *
 * THERE IS NO STATUS, AND THERE IS NO CACHED BALANCE
 * --------------------------------------------------
 * Unpaid / partial / paid are derived by summing allocations, so none of them is
 * a column and none of them is an accessor here. **`ChargeBalance` is the single
 * definition of outstanding**, in SQL for the tables that sort on it and in PHP
 * for the Actions that check an overpayment under lock. Summing
 * `$charge->allocations` in PHP would be a second definition, and it would add
 * `decimal:3` strings — which PHP does as float, losing exactly the dirham this
 * domain exists to keep (design sections 4, 6 and 11).
 *
 * EVERY FIGURE IS FROZEN AT ISSUE
 * -------------------------------
 * `list_price`, `discount_percentage` and `amount` record what was billed on the
 * day it was billed. A later price change, or a discount definition being
 * deactivated, moves none of them. After an `AdjustChargeAction` correction
 * `amount` no longer equals `list_price × (100 − percentage) ÷ 100`, and that is
 * expected: the frozen figures record what was billed, and the activity log
 * records that a human corrected it — this table carries no adjustment columns
 * because the append-only log *is* that audit record.
 *
 * WRITING OFF IS NOT PAYING
 * -------------------------
 * `ChargeBalance` deliberately does not subtract a write-off, so the balance on
 * a receipt and the balance in the payment history agree. The aged outstanding
 * report filters on `written_off_at` instead. Nothing is erased and the debt
 * stays in the student's history (design section 4).
 *
 * @property int $id
 * @property string $reference
 */
#[Fillable([
    'enrollment_id',
    /*
     * Written twice by IssueChargeAction inside one transaction — a unique
     * placeholder at insert, then the real `CHG-` value once the row has the id
     * the reference is built from. Fillable because both writes are mass
     * assignments; the write boundary is the Action, not the guarded list.
     */
    'reference',
    'list_price',
    'discount_id',
    'discount_percentage',
    'amount',
    'due_date',
    'written_off_at',
    'written_off_by',
    'written_off_reason',
])]
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The enrolment this bill was raised for. Never null, and never more than
     * one bill per enrolment — `enrollment_id` is UNIQUE.
     *
     * DECLARING THIS IS ALLOWED. WALKING IT TO THE STUDENT IS NOT.
     * -----------------------------------------------------------
     * Design section 12 permits a `belongsTo` where a real foreign key exists,
     * and the architecture test is aimed at Finance *querying* Enrolment models
     * rather than at the declaration. But `RecordPaymentAction` resolves the
     * owning student **through `EnrollmentQueryService`**, not through
     * `$charge->enrollment->student` — design section 5 names that walk
     * specifically as the thing not to do. This relation is for a display join
     * and for the receipt's enrolment reference, and reaching the student
     * through it is a domain-boundary violation with a passing test suite.
     *
     * No withTrashed(): enrolments do not soft-delete, and `enrollment_id`
     * restricts, so the parent cannot go away underneath this relation at all.
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * The definition the discount was taken from, if one was applied.
     *
     * Null on a full-price bill. The percentage that was actually applied is
     * frozen in `discount_percentage` on this row and is read from there, never
     * through this relation — a definition can be deactivated or replaced, and a
     * receipt reprinted years later still has to show the figure that was
     * charged.
     *
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * The super admin who retired this debt, if anyone has.
     *
     * withTrashed(), because users soft-delete while this foreign key restricts:
     * without it the account leaving turns a write-off into one nobody appears
     * to have made, on a row whose whole point is that a named person decided it.
     *
     * @return BelongsTo<User, $this>
     */
    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by')->withTrashed();
    }

    /**
     * Every payment allocation that has settled part of this bill.
     *
     * NOT A WRITE PATH. Allocations are written by `RecordPaymentAction` inside
     * the transaction that checks, under the charge's lock, that the tender
     * total equals the allocation total and that nothing exceeds outstanding. A
     * bare `$charge->allocations()->create()` reaches around both invariants.
     *
     * NOT A BALANCE, EITHER — see the class docblock. This relation lists the
     * rows; `ChargeBalance` is what sums them, and it filters out the
     * allocations of reversed payments, which this relation does not.
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Has this debt been retired?
     *
     * Derived from the fact column, never stored — design section 4 forbids a
     * status column throughout Finance, and this is the one lifecycle question
     * the table can answer from a single row. The other three states (unpaid,
     * partial, paid) need `ChargeBalance`, because they are sums of other rows.
     */
    public function isWrittenOff(): bool
    {
        return $this->written_off_at !== null;
    }

    /**
     * Everything a human decided, and one column deliberately missing.
     *
     * `reference` IS DELIBERATELY ABSENT, AND THE OMISSION IS THE FEATURE.
     * ------------------------------------------------------------------
     * RecordsActivity logs on model events, not on Actions. `IssueChargeAction`
     * inserts the row carrying `Reference::placeholder()` and replaces it with
     * the real `CHG-` value in the same transaction, so listing `reference` here
     * would file two entries: a `created` recording `reference = <uuid>`, and an
     * `updated` recording a phantom "the reference changed" beside it. Sharing a
     * transaction does not help — both commit with everything else, into a log
     * that has no delete path for any role, including super admin.
     *
     * Excluding it costs nothing. The reference is a deterministic function of
     * the subject id the log already records — `CHG-2026-000042` *is* charge 42 —
     * so the trail can still name the document. And because no audited column
     * moves during the replacement, `dontLogEmptyChanges()` suppresses that
     * `updated` entry entirely rather than filing an empty one.
     *
     * Design section 2 requires this on every table carrying a reference;
     * Enrollment::auditedAttributes() carries the same exclusion for the same
     * reason.
     */
    public function auditedAttributes(): array
    {
        return [
            'enrollment_id',
            'list_price',
            'discount_id',
            'discount_percentage',
            'amount',
            'due_date',
            'written_off_at',
            'written_off_by',
            'written_off_reason',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:3 on every money column. Never float, never two places —
            // LYD subdivides into 1000 dirham, and an architecture test forbids
            // a float cast anywhere in this domain.
            'list_price' => 'decimal:3',
            'discount_percentage' => 'decimal:2',
            'amount' => 'decimal:3',

            // A date, not a datetime: aging is counted in whole days against the
            // local calendar, and the column is `date`.
            'due_date' => 'date',

            'written_off_at' => 'datetime',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): ChargeFactory
    {
        return ChargeFactory::new();
    }
}
