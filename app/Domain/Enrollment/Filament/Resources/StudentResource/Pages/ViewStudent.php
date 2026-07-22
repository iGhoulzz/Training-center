<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Student;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only student record.
 *
 * This page is what makes the staff role's view-only grant mean something:
 * without it the register would list students that front-desk staff could never
 * open. Filament renders the resource form disabled here, and
 * ViewRecord::authorizeAccess() aborts 403 unless StudentPolicy::view() passes.
 */
class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * A plain link to the edit page rather than an EditAction, which would open
     * a modal built from this form and save it without the edit page's hooks.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('enrollment.edit_student'))
                ->visible(fn (Student $record): bool => StudentResource::canEdit($record))
                ->url(fn (Student $record): string => StudentResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
