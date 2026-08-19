<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Domain\Staff\Support\ActivityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use RuntimeException;

/** Render one finalized payment's private receipt, safely across queue retries. */
final class GenerateReceiptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $paymentId) {}

    public function handle(EnrollmentQueryService $enrollments, AttachReceiptAction $receipts): void
    {
        $payment = Payment::query()
            ->with(['tenders', 'allocations.charge', 'recordedBy'])
            ->findOrFail($this->paymentId);

        if ($payment->receipt_disk !== null || $payment->receipt_path !== null) {
            return;
        }

        $allocation = $payment->allocations->sole();
        $charge = $allocation->charge;
        $context = $enrollments->receiptContextFor((int) $charge->enrollment_id);
        $path = 'receipts/'.$payment->reference.'.pdf';
        $tenders = $payment->tenders->map(fn ($tender): array => [
            'label' => $tender->method->label(),
            'amount' => (string) $tender->amount,
        ])->all();
        $amountPaid = $payment->tenders->reduce(
            fn (Money $total, $tender): Money => $total->add(Money::fromDecimal((string) $tender->amount)),
            Money::zero(),
        );

        File::ensureDirectoryExists(storage_path('app/mpdf'));

        $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
        $mpdf->SetCompression(false);
        $mpdf->WriteHTML(view('finance.receipt', [
            'payment' => $payment,
            'charge' => $charge,
            'context' => $context,
            'tenders' => $tenders,
            'amountPaid' => $amountPaid->toDecimal(),
            'remainingBalance' => ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal(),
        ])->render());

        $bytes = $mpdf->OutputBinaryData();

        if (! Storage::disk(AttachReceiptAction::DISK)->put($path, $bytes)) {
            throw new RuntimeException("Receipt [{$payment->reference}] could not be written to private storage.");
        }

        if (! $receipts->execute((int) $payment->getKey(), $path)) {
            return;
        }

        activity()
            ->causedBy($payment->recordedBy)
            ->performedOn($payment)
            ->event(ActivityEvent::RECEIPT_GENERATED)
            ->log(ActivityEvent::RECEIPT_GENERATED);
    }
}
