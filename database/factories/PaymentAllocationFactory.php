<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * Fresh parents on both sides. `unique(payment_id, charge_id)` allows
             * one allocation per payment per bill, so a test settling one charge
             * from two payments passes the charge explicitly to both and lets the
             * payments differ.
             */
            'payment_id' => Payment::factory(),
            'charge_id' => Charge::factory(),

            /*
             * `CHECK (amount > 0)`. Comfortably below ChargeFactory's smallest
             * default list price, so a default allocation against a default
             * charge leaves the bill **part-paid** — the state most balance
             * assertions actually want, and one that never accidentally trips the
             * overpayment condition `RecordPaymentAction` derives under lock.
             *
             * There is deliberately no upper-bound constraint to satisfy: "never
             * more than outstanding" is derived from other rows and cannot be a
             * CHECK (design section 11).
             *
             * A decimal STRING, never a float.
             */
            'amount' => '100.000',
        ];
    }
}
