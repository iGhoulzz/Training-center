<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Actions\ReconcilePendingReceiptsAction;
use Illuminate\Console\Command;

/** Scheduled entry point for receipt-generation reconciliation. */
final class ReconcilePendingReceiptsCommand extends Command
{
    protected $signature = 'receipts:reconcile-pending';

    protected $description = 'Queue generation for committed payments whose receipt job was lost';

    public function handle(ReconcilePendingReceiptsAction $receipts): int
    {
        $receipts->execute();

        return self::SUCCESS;
    }
}
