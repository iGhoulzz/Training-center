<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One handover of money, and the receipt the student takes away.
 *
 * Configuration only — casts, five relations, one scope, one derived predicate.
 * No business logic and no write guards; see App\Models\User for why that
 * architecture was removed in P1-T04c.
 *
 * THERE IS NO amount, NO method AND NO status
 * -------------------------------------------
 * The total is `SUM(payment_tenders.amount)`, because 300 on card and 700 in
 * cash is one payment with two tenders and one `method` column cannot say that.
 * Payment state derives from `reversed_at` alone (design section 5). No accessor
 * here totals the tenders, for the reason design section 6 gives: aggregation
 * belongs in SQL, where DECIMAL sums are exact.
 *
 * EVERY ROW IS BY DEFINITION FINALIZED
 * ------------------------------------
 * There is no draft state. Payment, tenders and allocations are written in one
 * transaction, so a half-finished payment cannot exist to be misread later.
 * **Nothing should look for a finalized flag; there is none, and adding one
 * would be a derived value.**
 *
 * THE STUDENT IS DERIVED, NEVER SUPPLIED
 * --------------------------------------
 * `RecordPaymentAction` takes the bill, not a student, and resolves the owner
 * from the locked charge through `EnrollmentQueryService`. Its DTO has no
 * student field at all, so a crafted request cannot attach a payment to one
 * student while settling another's bill — there is nothing to attach. The column
 * exists because every report and the per-student history read it directly; it
 * is not an input (design section 5).
 *
 * REVERSAL IS SET-ONCE ON AN OTHERWISE IMMUTABLE ROW
 * --------------------------------------------------
 * `reversed_at`, `reversed_by` and `reversal_reason` are written once, by
 * `ReversePaymentAction` under `reverse_payment`, and never unset. The financial
 * facts — student, reference, tenders, allocations — are never rewritten and
 * never deleted. `PaymentPolicy::update()`, `delete()` and `deleteAny()` return
 * false unconditionally, and `payment_tenders.payment_id` restricts, so the
 * database refuses a delete while any tender exists.
 *
 * @property int $id
 * @property string $reference
 */
#[Fillable([
    'student_id',
    /*
     * Written twice by RecordPaymentAction inside one transaction — a unique
     * placeholder at insert, then the real `RCT-` value once the row has the id
     * the reference is built from. Fillable because both writes are mass
     * assignments; the write boundary is the Action, not the guarded list.
     */
    'reference',
    'idempotency_key',
    'request_fingerprint',
    'received_at',
    'recorded_by',
    'notes',
    'reversed_at',
    'reversed_by',
    'reversal_reason',
    // Set once, after commit, by AttachReceiptAction — the receipt cannot be
    // rendered until the payment it describes exists. See the class docblock.
    'receipt_disk',
    'receipt_path',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The person who paid.
     *
     * withTrashed() IS LOAD-BEARING. Students soft-delete and this foreign key
     * restricts, so a deleted student's payments survive them. Without it the
     * SoftDeletes global scope resolves this relation to null on exactly those
     * rows, and a receipt or a payment history would name nobody — for money the
     * centre certainly took. Enrollment::student() carries the same reasoning.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /**
     * The staff member at the desk.
     *
     * withTrashed(), because users soft-delete while this restricts: an account
     * leaving must not turn money into money nobody appears to have taken.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /**
     * The super admin who reversed this payment, if anyone has.
     *
     * withTrashed(), for the same reason as recordedBy().
     *
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }

    /**
     * How the money actually arrived — one row per method, at least one.
     *
     * NOT A WRITE PATH. Tenders are written by `RecordPaymentAction` inside the
     * transaction that checks the tender total against the allocation total
     * under the charge's lock. A bare `$payment->tenders()->create()` reaches
     * around that invariant and leaves a payment that no longer adds up.
     *
     * @return HasMany<PaymentTender, $this>
     */
    public function tenders(): HasMany
    {
        return $this->hasMany(PaymentTender::class);
    }

    /**
     * Which bill or bills this payment settled, and by how much.
     *
     * NOT A WRITE PATH, for the same reason as tenders(). **Staff never see the
     * word "allocation" and never make an allocation decision** — the phase 2 UI
     * always targets exactly one bill, so the allocation is decided by the
     * context the operator opened (design section 2).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The immutable document input captured when this payment was recorded.
     *
     * @return HasOne<PaymentReceiptSnapshot, $this>
     */
    public function receiptSnapshot(): HasOne
    {
        return $this->hasOne(PaymentReceiptSnapshot::class);
    }

    /**
     * Limit a query to payments that still count as collected revenue.
     *
     * Design section 5 requires this to be asserted per report rather than
     * assumed, and `ChargeBalance` applies the same `reversed_at IS NULL` rule in
     * raw SQL. Both are the one rule; a report that forgets it silently counts
     * reversed money again.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotReversed(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    /**
     * Has this payment been undone?
     *
     * Derived from the fact column, never stored — design section 4's "no status
     * column" rule holds across the whole Finance domain, and this is the only
     * lifecycle state a payment has.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * What the payment says happened, and four columns that are not that.
     *
     * `reference` IS DELIBERATELY ABSENT, AND THE OMISSION IS THE FEATURE.
     * ------------------------------------------------------------------
     * RecordsActivity logs on model events, not on Actions. `RecordPaymentAction`
     * inserts the row carrying `Reference::placeholder()` and replaces it with
     * the real `RCT-` value in the same transaction, so listing `reference` here
     * would file two entries: a `created` recording `reference = <uuid>`, and an
     * `updated` recording a phantom "the reference changed" beside it. Sharing a
     * transaction does not help — both commit with everything else, into a log
     * that has no delete path for any role, including super admin.
     *
     * Excluding it costs nothing. The reference is a deterministic function of
     * the subject id the log already records — `RCT-2026-000042` *is* payment 42
     * — and because no audited column moves during the replacement,
     * `dontLogEmptyChanges()` suppresses the `updated` entry entirely.
     *
     * Design section 2 requires this on every table carrying a reference.
     *
     * THREE MORE EXCLUSIONS, EACH DECIDED RATHER THAN FORGOTTEN
     * --------------------------------------------------------
     * `idempotency_key` and `request_fingerprint` are retry-protection mechanics
     * rather than facts about money: the key identifies a submission and the
     * fingerprint is a digest of a payload whose every field the log already
     * records through the tender breakdown. Logging a digest tells a reader
     * nothing they could act on.
     *
     * `receipt_disk` and `receipt_path` are a pointer to a rendered document,
     * written after commit by `AttachReceiptAction`. StaffProfile excludes
     * `profile_photo_path` for the same two reasons and they both apply here: a
     * storage path is not an audit fact, and a filename carries the reference and
     * the student it was rendered for. Task 6 records the generation as its own
     * semantic event instead.
     */
    public function auditedAttributes(): array
    {
        return [
            'student_id',
            'received_at',
            'recorded_by',
            'notes',
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
            /*
             * datetime, not date. Every report converts a local calendar period
             * in Africa/Tripoli into a half-open UTC range and compares
             * `received_at >= start AND received_at < end` (design section 8), so
             * the instant matters and the conversion is CentreCalendar's.
             */
            'received_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
