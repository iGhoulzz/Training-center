<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\Money;
use App\Support\CentreCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $paymentId) {}

    public function handle(EnrollmentQueryService $enrollments, AttachReceiptAction $receipts): void
    {
        $payment = Payment::query()
            ->with(['tenders', 'allocations.charge', 'recordedBy'])
            ->findOrFail($this->paymentId);

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
            'remainingBalance' => $this->remainingBalanceAtPayment($payment, $charge->amount),
            'paymentDate' => CentreCalendar::localise($payment->received_at)->format('Y-m-d H:i'),
        ])->render());

        $bytes = $mpdf->OutputBinaryData();

        $receipts->execute((int) $payment->getKey(), $path, $bytes);
    }

    /**
     * Reversals are stored at second precision, so equality is treated as later:
     * only a reversal strictly before this receipt excludes its allocation.
     */
    private function remainingBalanceAtPayment(Payment $payment, string $chargeAmount): string
    {
        $receivedAt = $payment->received_at;

        $allocated = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.charge_id', $payment->allocations->sole()->charge_id)
            ->where(function ($query) use ($payment, $receivedAt): void {
                $query->where('payments.received_at', '<', $receivedAt)
                    ->orWhere(function ($query) use ($payment, $receivedAt): void {
                        $query->where('payments.received_at', $receivedAt)
                            ->where('payments.id', '<=', $payment->getKey());
                    });
            })
            ->where(function ($query) use ($receivedAt): void {
                $query->whereNull('payments.reversed_at')
                    ->orWhere('payments.reversed_at', '>=', $receivedAt);
            })
            ->sum('payment_allocations.amount');

        return Money::fromDecimal($chargeAmount)
            ->subtract(Money::fromDecimal((string) $allocated))
            ->toDecimal();
    }
}
