<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Support\ReceiptLocation;
use App\Support\CentreCalendar;
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
    ): void {
        $snapshot = PaymentReceiptSnapshot::query()
            ->where('payment_id', $this->paymentId)
            ->firstOrFail();
        $tenderRows = PaymentTender::query()
            ->where('payment_id', $this->paymentId)
            ->orderBy('id')
            ->get();

        $originalLocale = app()->getLocale();

        try {
            app()->setLocale($snapshot->locale);
            $direction = str_starts_with($snapshot->locale, 'ar') ? 'rtl' : 'ltr';
            $tenders = $tenderRows->map(fn (PaymentTender $tender): array => [
                'label' => $tender->method->label(),
                'amount' => (string) $tender->amount,
            ])->all();

            File::ensureDirectoryExists(storage_path('app/mpdf'));

            $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
            $mpdf->SetCompression(false);
            $mpdf->WriteHTML(view('finance.receipt', [
                'snapshot' => $snapshot,
                'tenders' => $tenders,
                'paymentDate' => CentreCalendar::localise($snapshot->received_at)->format('Y-m-d H:i'),
                'locale' => $snapshot->locale,
                'direction' => $direction,
            ])->render());

            $bytes = $mpdf->OutputBinaryData();
        } finally {
            app()->setLocale($originalLocale);
        }

        $receipts->execute(
            $this->paymentId,
            $locations->pathForReference($snapshot->payment_reference),
            $bytes,
        );
    }
}
