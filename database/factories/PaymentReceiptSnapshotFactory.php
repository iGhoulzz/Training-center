<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentReceiptSnapshot> */
class PaymentReceiptSnapshotFactory extends Factory
{
    protected $model = PaymentReceiptSnapshot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'locale' => 'en',
            'student_code' => 'STU-'.fake()->unique()->numerify('######'),
            'student_name' => fake()->name(),
            'enrollment_reference' => 'ENR-2026-000001',
            'course_code' => 'CRS-001',
            'batch_code' => 'BAT-001',
            'charge_reference' => 'CHG-2026-000001',
            'list_price' => '1000.000',
            'discount_percentage' => null,
            'final_charge' => '1000.000',
            'amount_paid' => '300.000',
            'remaining_balance' => '700.000',
            'recorded_by_name' => fake()->name(),
            'payment_reference' => 'RCT-2026-000001',
            'received_at' => now(),
            'last_reconciliation_attempt_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PaymentReceiptSnapshot $snapshot): void {
            $payment = Payment::query()->findOrFail($snapshot->payment_id);

            $snapshot->payment_reference = $payment->reference;
            $snapshot->received_at = $payment->received_at;
        });
    }
}
