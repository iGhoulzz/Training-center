<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\Reference;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),

            /*
             * A unique placeholder, replaced with the real `RCT-` value in
             * configure(). See EnrollmentFactory for the full reasoning.
             */
            'reference' => Reference::placeholder(),

            /*
             * NOT NULL UNIQUE, and the one mechanism that stops a double-clicked
             * button becoming two payments and two receipts for one handover of
             * cash. A fresh UUID per row, because a shared key is what a replay
             * looks like — a test asserting the replay path should pass the same
             * key to two calls deliberately.
             */
            'idempotency_key' => Str::uuid()->toString(),

            /*
             * A hex SHA-256, which is what the 64-character column was sized for.
             * **Task 4 chooses the real canonicalisation** — the charge id, the
             * allocation amount and each tender's method, amount and trimmed
             * reference in a stable order — so this is a well-shaped placeholder
             * rather than a second implementation of it. Random per row: two rows
             * sharing a fingerprint means nothing while their keys differ.
             */
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),

            /*
             * A timestamp, not a date. Every report converts a local calendar
             * period in Africa/Tripoli into a half-open UTC range and compares
             * against this column, so the instant matters.
             */
            'received_at' => now(),

            'recorded_by' => User::factory(),
            'notes' => null,

            // All three reversal columns null together, per
            // `payments_reversal_columns_paired`. See reversed().
            'reversed_at' => null,
            'reversed_by' => null,
            'reversal_reason' => null,

            /*
             * Null, because the receipt is rendered after commit by a job and a
             * payment spends the moment it matters most without one. A fixture
             * that always carried a path would never exercise the window every
             * screen has to handle.
             */
            'receipt_disk' => null,
            'receipt_path' => null,
        ];
    }

    /**
     * Replace the placeholder with the real receipt number, once the row has an id.
     *
     * Dated by `received_at` — the moment the money changed hands — read on the
     * centre's calendar rather than UTC, so a payment taken at 00:30 local on
     * 1 January carries the year the person holding the receipt would say it did.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Payment $payment): void {
            $payment->reference = Reference::format(
                Reference::PAYMENT_PREFIX,
                CentreCalendar::yearOf($payment->received_at),
                (int) $payment->getKey(),
            );

            $payment->saveQuietly();
        });
    }

    /**
     * A payment that has been undone.
     *
     * Set-once on an otherwise immutable row, and all three columns move together
     * because `payments_reversal_columns_paired` refuses a reversal with no actor
     * or no reason. **Nothing is deleted**: the tenders and allocations stay
     * exactly as they were, and `reversed_at` is the only thing that takes them
     * out of every balance and every revenue figure.
     */
    public function reversed(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'reversed_at' => now(),
            'reversed_by' => $actor?->getKey() ?? User::factory(),
            'reversal_reason' => fake()->sentence(),
        ]);
    }

    /**
     * A payment whose receipt has been rendered.
     *
     * The path points at the private disk; no file is written, because the
     * columns store a pointer and nothing in the model touches the filesystem.
     * StaffProfileFactory::withPhoto() takes the same position.
     */
    public function withReceipt(): static
    {
        return $this->state(fn (): array => [
            'receipt_disk' => 'private',
            'receipt_path' => 'receipts/'.Str::ulid()->toString().'.pdf',
        ]);
    }
}
