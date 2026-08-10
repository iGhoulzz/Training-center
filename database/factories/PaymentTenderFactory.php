<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentTender>
 */
class PaymentTenderFactory extends Factory
{
    protected $model = PaymentTender::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),

            /*
             * Cash, deliberately. It is the method with no further requirement,
             * so the default row satisfies
             * `payment_tenders_card_requires_external_reference` without carrying
             * a terminal reference it would have no reason to have. card() is the
             * state that opts into the constrained branch.
             */
            'method' => TenderMethod::Cash,

            /*
             * `CHECK (amount > 0)` — strictly positive, unlike `charges.amount`.
             * A bill can legitimately be 0.000 after a full waiver, but nobody
             * hands over nothing. A decimal STRING, never a float.
             */
            'amount' => (string) fake()->numberBetween(50, 500).'.000',

            // Null for cash, and required-and-non-blank for card. See card().
            'external_reference' => null,
        ];
    }

    /**
     * A card tender, with the terminal reference the database insists on.
     *
     * `payment_tenders_card_requires_external_reference` uses `TRIM`, so a single
     * space is refused as well as a null — a space is an operator tabbing past
     * the field, and it leaves a card transaction with nothing to reconcile
     * against the terminal's own log. **A card state that forgot this would make
     * every later task's card fixture fail on a constraint rather than on its own
     * assertion**, which is why the value here is generated rather than omitted.
     *
     * Not PAN-shaped: task 4's validation rule rejects 13 to 19 digits, and card
     * numbers are never stored anywhere in this system.
     */
    public function card(): static
    {
        return $this->state(fn (): array => [
            'method' => TenderMethod::Card,
            'external_reference' => 'AUTH-'.Str::upper(Str::random(10)),
        ]);
    }

    /** Money arriving in the centre's account, evidenced outside this system. */
    public function bankTransfer(): static
    {
        return $this->state(fn (): array => [
            'method' => TenderMethod::BankTransfer,
            'external_reference' => 'TRF-'.Str::upper(Str::random(10)),
        ]);
    }
}
