<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Batch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only intake.
 *
 * This page is what makes the staff role's view-only grant mean something:
 * without it the schedule would list batches that front-desk staff could never
 * open, and answering "when does the next English B1 start" is exactly their
 * job. Filament renders the resource form disabled here, and
 * ViewRecord::authorizeAccess() aborts 403 unless BatchPolicy::view() passes.
 */
class ViewBatch extends ViewRecord
{
    protected static string $resource = BatchResource::class;

    /**
     * A plain link to the edit page rather than an EditAction, which would open
     * a modal built from this form and save it without the edit page's hooks.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('enrollment.edit_batch'))
                ->visible(fn (Batch $record): bool => BatchResource::canEdit($record))
                ->url(fn (Batch $record): string => BatchResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
