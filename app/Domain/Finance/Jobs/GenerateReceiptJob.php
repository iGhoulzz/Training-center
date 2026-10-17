<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ReceiptLocation;
use App\Domain\Finance\Support\ReceiptViewData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/** Render one finalized payment's private receipt, safely across queue retries. */
final class GenerateReceiptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Must remain below the database queue's 90-second retry_after. */
    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $paymentId) {}

    public function handle(
        ReceiptLocation $locations,
        AttachReceiptAction $receipts,
        ReceiptViewData $viewData,
    ): void {
        $payment = Payment::query()->findOrFail($this->paymentId);
        // The stored path is derived from the snapshot's reference, as it always
        // was; AttachReceiptAction refuses it unless it matches the payment's.
        $snapshot = $payment->receiptSnapshot()->firstOrFail();

        // Built and rendered in the snapshot's locale by ReceiptViewData, the
        // same place the 80 mm print page is built, so the PDF and the paper
        // cannot drift apart. Only the PDF conversion lives here.
        $html = $viewData->render('finance.receipt', $payment);

        File::ensureDirectoryExists(storage_path('app/mpdf'));

        $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
        $mpdf->SetCompression(false);
        $mpdf->WriteHTML($html);

        $bytes = $mpdf->OutputBinaryData();

        $receipts->execute(
            $this->paymentId,
            $locations->pathForReference($snapshot->payment_reference),
            $bytes,
        );
    }
}
