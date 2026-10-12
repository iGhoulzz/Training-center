<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Batch;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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

    public function content(Schema $schema): Schema
    {
        /** @var Batch $batch */
        $batch = $this->getRecord();

        return $schema->components([
            Section::make($batch->code)->description($batch->course->name())->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('summaryStatus')->label(__('enrollment.status'))->state($batch->status->label())->badge(),
                TextEntry::make('summarySeats')->label(__('enrollment.enrolment_load'))
                    ->state(__('enrollment.enrolment_load_value', [
                        'active' => $batch->activeEnrollmentCount(),
                        'capacity' => $batch->capacity > 0 ? $batch->capacity : __('enrollment.no_capacity_limit_short'),
                    ]))->badge()->color($batch->isOverCapacity() ? 'warning' : 'gray')
                    ->helperText($batch->isOverCapacity() ? __('enrollment.over_capacity_warning') : null),
                TextEntry::make('summaryHours')->label(__('enrollment.hour_allocation'))
                    ->state(__('enrollment.hours_fraction', ['assigned' => $batch->totalAssignedHours(), 'total' => $batch->effective_total_hours]))
                    ->badge()->color($batch->hasHourMismatch() ? 'warning' : 'success')
                    ->helperText($batch->hasHourMismatch() ? __('enrollment.hour_mismatch_hint') : __('enrollment.hours_matched')),
                TextEntry::make('summaryStart')->label(__('enrollment.start_date'))->state($batch->start_date)->date()->placeholder(__('enrollment.no_date')),
                TextEntry::make('summaryEnd')->label(__('enrollment.end_date'))->state($batch->end_date)->date()->placeholder(__('enrollment.no_date')),
                TextEntry::make('summaryTotalHours')->label(__('enrollment.total_hours'))->state($batch->effective_total_hours),
            ]),
            $this->getRelationManagersContentComponent(),
            Section::make(__('enrollment.batch_details'))->collapsible()->collapsed()->schema([EmbeddedSchema::make('form')]),
        ]);
    }

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
