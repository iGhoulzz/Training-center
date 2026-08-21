<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use Database\Factories\PaymentReceiptSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable facts printed on one payment receipt.
 *
 * This is document input, not a finance read model. Balances, reports,
 * validation and authorization continue to read the source rows. The snapshot
 * exists only so a delayed or retried renderer prints exactly what the person
 * at the counter was told when the money was recorded.
 */
#[Fillable([
    'payment_id',
    'locale',
    'student_code',
    'student_name',
    'enrollment_reference',
    'course_code',
    'batch_code',
    'charge_reference',
    'list_price',
    'discount_percentage',
    'final_charge',
    'amount_paid',
    'remaining_balance',
    'recorded_by_name',
    'payment_reference',
    'received_at',
    'last_reconciliation_attempt_at',
])]
class PaymentReceiptSnapshot extends Model
{
    /** @use HasFactory<PaymentReceiptSnapshotFactory> */
    use HasFactory;

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:3',
            'discount_percentage' => 'decimal:2',
            'final_charge' => 'decimal:3',
            'amount_paid' => 'decimal:3',
            'remaining_balance' => 'decimal:3',
            'received_at' => 'datetime',
            'last_reconciliation_attempt_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentReceiptSnapshotFactory
    {
        return PaymentReceiptSnapshotFactory::new();
    }
}
