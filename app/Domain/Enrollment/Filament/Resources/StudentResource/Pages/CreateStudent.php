<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A plain create page: a student record is ordinary data with no Action-owned
 * write behaviour, so Filament's own persistence is the whole of it. Access is
 * gated by CreateRecord::authorizeAccess(), which aborts 403 unless the actor
 * passes StudentPolicy::create().
 */
class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;
}
