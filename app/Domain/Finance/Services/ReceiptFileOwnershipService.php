<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ReceiptLocation;
use Illuminate\Support\Facades\DB;

/** Resolve exact receipt-file ownership on the caller-supplied connection. */
final class ReceiptFileOwnershipService
{
    public function __construct(private readonly ReceiptLocation $locations) {}

    public function owns(string $connection, string $disk, string $path): bool
    {
        if ($disk !== ReceiptLocation::DISK) {
            return false;
        }

        return DB::connection($connection)->transaction(function () use ($connection, $disk, $path): bool {
            $payment = Payment::on($connection)
                ->where('receipt_disk', $disk)
                ->where('receipt_path', $path)
                ->lockForUpdate()
                ->first();

            return $payment instanceof Payment
                && $this->locations->isCanonical($payment, $disk, $path);
        });
    }
}
