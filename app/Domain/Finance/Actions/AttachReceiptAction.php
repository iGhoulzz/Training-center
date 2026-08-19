<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Payment;
use App\Domain\Staff\Support\ActivityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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
    public function execute(int $paymentId, string $path, string $contents): bool
    {
        $wroteReceipt = false;

        try {
            return DB::transaction(function () use ($paymentId, $path, $contents, &$wroteReceipt): bool {
                $payment = Payment::query()
                    ->with('recordedBy')
                    ->lockForUpdate()
                    ->findOrFail($paymentId);

                if ($payment->receipt_disk !== null || $payment->receipt_path !== null) {
                    if ($payment->receipt_disk === null || $payment->receipt_path === null) {
                        throw new RuntimeException("Payment [{$paymentId}] has an incomplete receipt location.");
                    }

                    return false;
                }

                if (! Storage::disk(self::DISK)->put($path, $contents)) {
                    throw new RuntimeException("Receipt [{$path}] could not be written to private storage.");
                }

                $wroteReceipt = true;

                $payment->update([
                    'receipt_disk' => self::DISK,
                    'receipt_path' => $path,
                ]);

                activity()
                    ->causedBy($payment->recordedBy)
                    ->performedOn($payment)
                    ->event(ActivityEvent::RECEIPT_GENERATED)
                    ->log(ActivityEvent::RECEIPT_GENERATED);

                return true;
            });
        } catch (Throwable $throwable) {
            if ($wroteReceipt) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $throwable;
        }
    }
}
