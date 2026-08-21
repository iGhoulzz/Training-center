<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Staff\Support\SafeReporting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Repair the narrow commit-then-enqueue-loss window for payment receipts. */
final class ReconcilePendingReceiptsAction
{
    public const LIMIT = 100;

    public const STALE_MINUTES = 10;

    /** @return int Number of jobs handed to the queue. */
    public function execute(): int
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);

        $ids = PaymentReceiptSnapshot::query()
            ->select('payment_receipt_snapshots.id')
            ->join('payments', 'payments.id', '=', 'payment_receipt_snapshots.payment_id')
            ->whereNull('payments.receipt_path')
            ->where('payment_receipt_snapshots.created_at', '<=', $cutoff)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('payment_receipt_snapshots.last_reconciliation_attempt_at')
                    ->orWhere('payment_receipt_snapshots.last_reconciliation_attempt_at', '<=', $cutoff);
            })
            ->orderByRaw('payment_receipt_snapshots.last_reconciliation_attempt_at IS NOT NULL')
            ->orderBy('payment_receipt_snapshots.last_reconciliation_attempt_at')
            ->orderBy('payment_receipt_snapshots.id')
            ->limit(self::LIMIT)
            ->pluck('payment_receipt_snapshots.id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $paymentId = DB::transaction(function () use ($id, $cutoff): ?int {
                $snapshot = PaymentReceiptSnapshot::query()
                    ->lockForUpdate()
                    ->find($id);

                if (! $snapshot instanceof PaymentReceiptSnapshot) {
                    return null;
                }

                $payment = Payment::query()
                    ->lockForUpdate()
                    ->find($snapshot->payment_id);

                if (
                    ! $payment instanceof Payment
                    || $payment->receipt_path !== null
                    || $snapshot->created_at->isAfter($cutoff)
                    || (
                        $snapshot->last_reconciliation_attempt_at !== null
                        && $snapshot->last_reconciliation_attempt_at->isAfter($cutoff)
                    )
                ) {
                    return null;
                }

                $snapshot->update(['last_reconciliation_attempt_at' => now()]);

                return (int) $payment->getKey();
            });

            if ($paymentId === null) {
                continue;
            }

            try {
                Bus::dispatch(new GenerateReceiptJob($paymentId));
                $dispatched++;
            } catch (Throwable $exception) {
                SafeReporting::report($exception);
            }
        }

        return $dispatched;
    }
}
