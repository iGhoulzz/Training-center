<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Batch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;

    /** @var array<string, int> */
    protected array $tabCounts = [];

    public function rendering(): void
    {
        $this->getTableRecords();
    }

    /**
     * @param  Builder<Batch>  $query
     * @return Paginator<int, Batch>|CursorPaginator<int, Batch>
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $total = $query->toBase()->getCountForPagination();
        $counts = DB::query();
        $names = [];
        foreach (['all' => Batch::query(), 'active' => Batch::query()->where('status', BatchStatus::Active),
            'planned' => Batch::query()->where('status', BatchStatus::Planned), 'over_capacity' => Batch::query()->overCapacity(),
            'hour_mismatch' => Batch::query()->hourMismatch()] as $name => $global) {
            $names[] = $name;
            $projection = $global->selectRaw('COUNT(*)');
            $query->selectSub(clone $projection, 'tab_count_'.$name);
            $counts->selectSub($projection, 'tab_count_'.$name);
        }
        $perPage = $this->getTableRecordsPerPage();
        /** @var LengthAwarePaginator<int, Batch> $records */
        $records = $query->paginate(perPage: $perPage === 'all' ? max(1, $total) : $perPage,
            pageName: $this->getTablePaginationPageName(), total: $total);
        $first = $records->first();
        // A filtered-empty or out-of-range page has no row carrying the global
        // projections. Only that case needs one bounded metadata fallback.
        $metadata = $first === null ? $counts->first() : null;
        foreach ($names as $name) {
            $alias = 'tab_count_'.$name;
            $this->tabCounts[$name] = (int) ($first?->getAttribute($alias) ?? $metadata?->{$alias});
        }

        return $records->onEachSide(0);
    }

    public function getSubheading(): string
    {
        return __('enrollment.schedule');
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('enrollment.all'))->badge(fn (): int => $this->tabCounts['all'] ?? 0)];
        foreach ([BatchStatus::Active, BatchStatus::Planned] as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge(fn (): int => $this->tabCounts[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }
        $tabs['over_capacity'] = Tab::make(__('enrollment.over_capacity'))
            ->badge(fn (): int => $this->tabCounts['over_capacity'] ?? 0)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('capacity', '>', 0)
                ->havingRaw(Batch::ACTIVE_ENROLLMENTS_COUNT.' > capacity'));
        $tabs['hour_mismatch'] = Tab::make(__('enrollment.hour_mismatch'))
            ->badge(fn (): int => $this->tabCounts['hour_mismatch'] ?? 0)
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
