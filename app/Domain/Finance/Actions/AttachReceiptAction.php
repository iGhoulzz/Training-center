<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attach a rendered receipt's private location exactly once.
 *
 * INTERNAL: GenerateReceiptJob is the sole caller. The payment was already
 * authorized when it was finalized, so this consequence has no public ability.
 */
final class AttachReceiptAction
{
    public const DISK = 'private';

    /** @return bool Whether this invocation attached the receipt. */
    public function execute(int $paymentId, string $path): bool
    {
        return DB::transaction(function () use ($paymentId, $path): bool {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->receipt_disk !== null || $payment->receipt_path !== null) {
                if ($payment->receipt_disk === null || $payment->receipt_path === null) {
                    throw new RuntimeException("Payment [{$paymentId}] has an incomplete receipt location.");
                }

                return false;
            }

            $payment->update([
                'receipt_disk' => self::DISK,
                'receipt_path' => $path,
            ]);

            return true;
        });
    }
}
