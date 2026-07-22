<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\CourseResource\Pages;

use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Models\Course;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only catalogue entry.
 *
 * This page is what makes the staff role's view-only grant mean something:
 * without it the catalogue would list courses that front-desk staff could never
 * open, and answering "how many hours is English B1" is exactly their job.
 * Filament renders the resource form disabled here, and
 * ViewRecord::authorizeAccess() aborts 403 unless CoursePolicy::view() passes.
 */
class ViewCourse extends ViewRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * A plain link to the edit page rather than an EditAction, which would open
     * a modal built from this form and save it without the edit page's hooks.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('enrollment.edit_course'))
                ->visible(fn (Course $record): bool => CourseResource::canEdit($record))
                ->url(fn (Course $record): string => CourseResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
