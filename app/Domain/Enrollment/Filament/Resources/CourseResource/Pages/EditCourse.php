<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\CourseResource\Pages;

use App\Domain\Enrollment\Filament\Resources\CourseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a catalogue entry.
 *
 * Access is gated by EditRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes CoursePolicy::update() — so view-only staff never reach this
 * page at all.
 *
 * Editing in place is the point of the course/batch split: correcting the hours
 * here changes what every inheriting batch reports, with no copies to chase.
 */
class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * authorize(), not merely visible(). visible() is a UX affordance that a
     * crafted Livewire mount ignores; authorize('delete') runs
     * CoursePolicy::delete() against this record, and an unauthorized action is
     * hidden AND unmountable. That matters because update_course and
     * delete_course are separate grants: reaching this page does not imply the
     * right to delete from it.
     *
     * A course with batches is refused by the database (restrictOnDelete), not
     * by this action. That refusal cannot be raced the way a policy check can.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete')
                ->successRedirectUrl(fn (): string => CourseResource::getUrl('index')),
        ];
    }
}
