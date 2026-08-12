<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Finance\Filament\Concerns\WritesPricingThroughActions;
use Filament\Resources\Pages\CreateRecord;

/**
 * Filament persists ordinary scheduling fields; the always-non-dehydrated price
 * is applied afterwards through UpdateBatchPriceAction. Instructor assignment
 * remains a separate Action-backed flow reached once the batch exists.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless
 * the actor passes BatchPolicy::create() — which staff do not.
 */
class CreateBatch extends CreateRecord
{
    use WritesPricingThroughActions;

    protected static string $resource = BatchResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        $this->record->refresh();

        $this->writeBatchPrice();
    }
}
