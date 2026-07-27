<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing an intake.
 *
 * Access is gated by EditRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes BatchPolicy::update() — so view-only staff never reach this page
 * at all.
 *
 * A completed or cancelled batch is still editable here, deliberately. The spec
 * gates enrolments and instructor changes on status, not the record itself, and
 * freezing the row would make a mis-clicked "completed" permanently
 * uncorrectable from the application. See BatchPolicy for the full reasoning.
 */
class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * authorize(), not merely visible(). visible() is a UX affordance that a
     * crafted Livewire mount ignores; authorize('delete') runs
     * BatchPolicy::delete() against this record, and an unauthorized action is
     * hidden AND unmountable. That matters because update_batch and delete_batch
     * are separate grants: reaching this page does not imply the right to delete
     * from it.
     */
    protected function getHeaderActions(): array
    {
        return [
            // The shared definition, so the edit page and the table row cannot
            // drift apart on what a refused delete does.
            BatchResource::deleteAction()
                ->successRedirectUrl(fn (): string => BatchResource::getUrl('index')),
        ];
    }
}
