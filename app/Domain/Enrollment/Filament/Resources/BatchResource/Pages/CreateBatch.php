<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A plain create page: scheduling an intake is ordinary data entry with no
 * Action-owned write behaviour, so Filament's own persistence is the whole of
 * it. Assigning instructors is the part that needs an Action, and that is
 * P1-T10's, reached from the batch once it exists.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless
 * the actor passes BatchPolicy::create() — which staff do not.
 */
class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;
}
