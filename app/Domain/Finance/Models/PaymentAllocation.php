<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which bill a payment settled, and by how much.
 *
 * Configuration only — casts and two relations. No business logic and no write
 * guards; see App\Models\User for why that architecture was removed in P1-T04c.
 *
 * EVERY BALANCE IN THE SYSTEM IS A SUM OF THESE ROWS
 * --------------------------------------------------
 * And **not one of those sums happens here.** `ChargeBalance` is the single
 * definition of outstanding, in raw SQL naming `payment_allocations.amount`,
 * `.charge_id` and `.payment_id` joined to `payments.reversed_at`. Design
 * section 6 puts aggregation in SQL because MySQL's DECIMAL sums are exact,
 * while adding `decimal:3` strings in PHP converts them to float — and 0.001, the
 * dirham, is exactly the digit that is lost.
 *
 * Those column names cannot be refactored by an IDE. Renaming one compiles fine
 * and fails at runtime, on a balance.
 *
 * STAFF NEVER SEE THE WORD "ALLOCATION"
 * -------------------------------------
 * They never make an allocation decision either. The phase 2 UI always targets
 * exactly one bill, so the allocation is decided by the context the operator
 * opened (design section 2). The many-charge capability stays in the schema so a
 * future "pay both my courses at once" flow needs no migration; design section 13
 * confirms that as a deliberate, accepted cost.
 *
 * THERE IS NO UPPER-BOUND CHECK, AND THERE CANNOT BE ONE
 * ------------------------------------------------------
 * `CHECK (amount > 0)` is in the database. "Never more than outstanding" is not,
 * because outstanding is derived from other rows — `RecordPaymentAction` derives
 * it under the charge's lock instead (design section 11). Nothing in this class
 * checks it either: a guard here would run outside that lock and would be
 * reassuring rather than true.
 *
 * @property int $id
 */
#[Fillable([
    'payment_id',
    'charge_id',
    'amount',
])]
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The money this row spent.
     *
     * No withTrashed(): payments do not soft-delete and `payment_id` restricts.
     * A payment that must be undone is reversed — which leaves this row exactly
     * as it is and takes it out of every balance through `payments.reversed_at`,
     * not by deleting anything.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The debt this row discharged.
     *
     * No withTrashed(): charges do not soft-delete and `charge_id` restricts, so
     * a bill carrying an allocation cannot be deleted at all —
     * `DeleteUncommittedChargeAction` defines an uncommitted bill as one carrying
     * no allocation, and this foreign key is what makes that definition
     * enforceable rather than merely intended.
     *
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** Which money, against which bill, for how much. */
    public function auditedAttributes(): array
    {
        return [
            'payment_id',
            'charge_id',
            'amount',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:3. Never float, never two places — and never summed in
            // PHP; see the class docblock.
            'amount' => 'decimal:3',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): PaymentAllocationFactory
    {
        return PaymentAllocationFactory::new();
    }
}
