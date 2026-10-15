<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Queries\BatchOutstanding;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
            View::make('filament.enrollment.batch-hero')->viewData([
                'code' => $batch->code, 'course' => $batch->course->name(), 'status' => $batch->status->label(),
                'statusColor' => $batch->status->isOpen() ? 'success' : 'gray',
                'dates' => ($batch->start_date?->format('d M') ?? __('enrollment.no_date')).' → '.($batch->end_date?->format('d M Y') ?? __('enrollment.no_date')),
                'totalHours' => $batch->effective_total_hours,
                'seatLabel' => __('enrollment.enrolment_load_value', ['active' => $batch->activeEnrollmentCount(), 'capacity' => $batch->capacity ?: __('enrollment.no_capacity_limit_short')]),
                'hourLabel' => __('enrollment.hours_fraction', ['assigned' => $batch->totalAssignedHours(), 'total' => $batch->effective_total_hours]),
                'over' => $batch->isOverCapacity(), 'mismatch' => $batch->hasHourMismatch(),
                'outstanding' => auth()->user()->can('view_any_charge') ? app(BatchOutstanding::class)->forBatch((int) $batch->getKey()) : null,
            ])->schema([Actions::make($this->getHeroActions())->key('hero-actions')]),
            $this->getRelationManagersContentComponent(),
            Section::make(__('enrollment.batch_details'))->collapsible()->collapsed()->schema([EmbeddedSchema::make('form')]),
        ]);
    }

    /**
     * A plain link to the edit page rather than an EditAction, which would open
     * a modal built from this form and save it without the edit page's hooks.
     *
     * @return array<Action>
     */
    private function getHeroActions(): array
    {
        return [
            Action::make('enroll')->label(__('enrollment.enroll_student'))
                ->visible(fn (Batch $record): bool => $record->acceptsEnrollments() && EnrollAndCollect::canAccess())
                ->url(fn (Batch $record): string => EnrollAndCollect::getUrl(['batch' => $record->getKey()])),
            Action::make('edit')
                ->label(__('enrollment.edit_batch'))
                ->visible(fn (Batch $record): bool => BatchResource::canEdit($record))
                ->url(fn (Batch $record): string => BatchResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
