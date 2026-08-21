<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ReceiptLocation;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Domain\Staff\Support\ActivityEvent;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Attach a rendered receipt's private location exactly once.
 *
 * INTERNAL: GenerateReceiptJob is the sole caller. The payment was already
 * authorized when it was finalized, so this consequence has no public ability.
 */
final class AttachReceiptAction
{
    public function __construct(
        private readonly ReceiptLocation $locations,
        private readonly FileLifecycleService $files,
    ) {}

    /** @return bool Whether this invocation attached the receipt. */
    public function execute(int $paymentId, string $path, string $contents): bool
    {
        $attached = $this->files->persistNewFileWithOwnerLock(
            ReceiptLocation::DISK,
            $path,
            lockOwner: function () use ($paymentId, $path): Payment|false {
                $payment = Payment::query()
                    ->with('recordedBy')
                    ->lockForUpdate()
                    ->findOrFail($paymentId);

                $this->locations->assertCanonical($payment, ReceiptLocation::DISK, $path);

                if ($payment->receipt_disk !== null || $payment->receipt_path !== null) {
                    if ($payment->receipt_disk === null || $payment->receipt_path === null) {
                        throw new RuntimeException("Payment [{$paymentId}] has an incomplete receipt location.");
                    }

                    $this->locations->assertCanonical(
                        $payment,
                        (string) $payment->receipt_disk,
                        (string) $payment->receipt_path,
                    );

                    return false;
                }

                return $payment;
            },
            store: function () use ($path, $contents): void {
                if (! Storage::disk(ReceiptLocation::DISK)->put($path, $contents)) {
                    throw new RuntimeException("Receipt [{$path}] could not be written to private storage.");
                }
            },
            persist: function (Payment $payment) use ($path): bool {
                $payment->update([
                    'receipt_disk' => ReceiptLocation::DISK,
                    'receipt_path' => $path,
                ]);

                activity()
                    ->causedBy($payment->recordedBy)
                    ->performedOn($payment)
                    ->event(ActivityEvent::RECEIPT_GENERATED)
                    ->log(ActivityEvent::RECEIPT_GENERATED);

                return true;
            },
        );

        return $attached === true;
    }
}
