<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Batch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;

    public function getSubheading(): string
    {
        return __('enrollment.schedule');
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('enrollment.all'))];
        foreach ([BatchStatus::Active, BatchStatus::Planned] as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }
        $tabs['over_capacity'] = Tab::make(__('enrollment.over_capacity'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('capacity', '>', 0)
                ->havingRaw(Batch::ACTIVE_ENROLLMENTS_COUNT.' > capacity'));
        $tabs['hour_mismatch'] = Tab::make(__('enrollment.hour_mismatch'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->havingRaw(
                'COALESCE('.Batch::ASSIGNED_HOURS_SUM.', 0) <> COALESCE(total_hours, (SELECT total_hours FROM courses WHERE courses.id = batches.course_id))',
            ));

        return $tabs;
    }

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction — the same
     * reasoning as ListStudents: CreateAction keeps a mountable server-side
     * create handler even when ->url() is set, and that handler persists with a
     * bare $model::create($data) that never runs the create page's hooks.
     *
     * canCreate() is checked explicitly because a plain Action carries no
     * authorization of its own; without it the button renders for view-only
     * staff, who then hit a 403 on the page behind it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('enrollment.create_batch'))
                ->visible(fn (): bool => BatchResource::canCreate())
                ->url(fn (): string => BatchResource::getUrl('create')),
        ];
    }
}
