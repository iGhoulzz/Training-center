<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\PaymentTenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one part of a payment was actually handed over.
 *
 * Configuration only — casts and one relation. No business logic and no write
 * guards; see App\Models\User for why that architecture was removed in P1-T04c.
 *
 * 300 ON CARD AND 700 IN CASH IS ONE PAYMENT, ONE RECEIPT, TWO ROWS HERE
 * ----------------------------------------------------------------------
 * This table replaced the original design's single `payments.method` column,
 * which cannot represent the split-tender checkout every retail counter performs
 * (design section 5). It is also why `payments` carries no amount: the total is
 * `SUM(payment_tenders.amount)` and never a stored copy of it.
 *
 * The payment method breakdown and the daily tender report therefore group by
 * **tender**, never by payment, so a split payment contributes to two methods
 * (design section 8).
 *
 * THE CARD RULE IS A DATABASE CONSTRAINT, AND IS NOT RESTATED HERE
 * ---------------------------------------------------------------
 * `payment_tenders_card_requires_external_reference` refuses a card tender whose
 * terminal reference is null or whitespace, with `TRIM` so a single space does
 * not pass for a reference. Adding a PHP mirror of it to this class would be a
 * second definition of the same rule that any other writer reaches around, and
 * would put a validation decision in a model that is configuration only. The
 * user-facing message for the same condition belongs to task 4's form request.
 *
 * WHAT IS NEVER STORED
 * --------------------
 * Card numbers, PINs and CVVs, anywhere in this system. `external_reference` is
 * the terminal's own reference, typed by whoever is at the desk, and task 4's
 * validation rule rejects PAN-shaped input.
 *
 * @property int $id
 */
#[Fillable([
    'payment_id',
    'method',
    'amount',
    'external_reference',
])]
class PaymentTender extends Model
{
    /** @use HasFactory<PaymentTenderFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The handover this tender is part of. Never null, and never alone — a
     * payment carries at least one.
     *
     * No withTrashed(): payments do not soft-delete, and `payment_id` restricts,
     * so the parent cannot go away underneath this relation. That restriction is
     * the point rather than a copy-paste of the financial default — it makes the
     * payment undeletable at the database level while any tender exists, which is
     * where design section 5's immutability claim stops resting on application
     * code alone.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** What arrived, how, and the terminal reference that evidences it. */
    public function auditedAttributes(): array
    {
        return [
            'payment_id',
            'method',
            'amount',
            'external_reference',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => TenderMethod::class,

            // decimal:3 on every money column. Never float, never two places —
            // LYD subdivides into 1000 dirham, and design section 11 forbids a
            // float cast anywhere in this domain.
            'amount' => 'decimal:3',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): PaymentTenderFactory
    {
        return PaymentTenderFactory::new();
    }
}
