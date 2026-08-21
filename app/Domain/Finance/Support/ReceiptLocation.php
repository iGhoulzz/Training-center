<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Models\Payment;
use App\Support\CentreCalendar;
use RuntimeException;

/** The one derivation and validation boundary for private receipt locations. */
final class ReceiptLocation
{
    public const DISK = 'private';

    public const DIRECTORY = 'receipts';

    public function reference(Payment $payment): string
    {
        return Reference::format(
            Reference::PAYMENT_PREFIX,
            CentreCalendar::yearOf($payment->received_at),
            (int) $payment->getKey(),
        );
    }

    public function pathForReference(string $reference): string
    {
        return self::DIRECTORY.'/'.$reference.'.pdf';
    }

    public function path(Payment $payment): string
    {
        return $this->pathForReference($this->reference($payment));
    }

    public function assertCanonical(Payment $payment, string $disk, string $path): void
    {
        $expectedReference = $this->reference($payment);
        $expectedPath = $this->pathForReference($expectedReference);

        if (
            $payment->reference !== $expectedReference
            || $disk !== self::DISK
            || $path !== $expectedPath
        ) {
            throw new RuntimeException("Payment [{$payment->getKey()}] has a non-canonical receipt location.");
        }
    }

    public function isCanonical(Payment $payment, string $disk, string $path): bool
    {
        try {
            $this->assertCanonical($payment, $disk, $path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
