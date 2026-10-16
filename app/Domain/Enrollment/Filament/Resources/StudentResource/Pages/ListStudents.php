<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Services\StudentBalanceQuery;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/** @property-read Student|null $selectedStudent */
class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    #[Url(as: 'student')]
    public ?int $selectedStudentId = null;

    /** @var array<string, int> */
    protected array $tabCounts = [];

    public function rendering(): void
    {
        $this->getTableRecords();
    }

    /**
     * @param  Builder<Student>  $query
     * @return Paginator<int, Student>|CursorPaginator<int, Student>
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $total = $query->toBase()->getCountForPagination();
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }
        $counts = DB::query();
        $names = [];
        foreach (['all' => Student::query(), 'active' => Student::query()->where('status', StudentStatus::Active),
            'prospective' => Student::query()->where('status', StudentStatus::Prospective),
            'no_portal' => Student::query()->whereNull('user_id')] as $name => $global) {
            $names[] = $name;
            $projection = $global->selectRaw('COUNT(*)');
            $query->selectSub(clone $projection, 'tab_count_'.$name);
            $counts->selectSub($projection, 'tab_count_'.$name);
        }
        if (auth()->user()?->can('view_any_charge')) {
            $names[] = 'owes_money';
            $owing = DB::query()->fromSub(app(StudentBalanceQuery::class)->withOutstanding(Student::query()), 'owing_students')
                ->where(StudentBalanceQuery::OUTSTANDING_ALIAS, '>', 0)->selectRaw('COUNT(*)');
            $query->selectSub(clone $owing, 'tab_count_owes_money');
            $counts->selectSub($owing, 'tab_count_owes_money');
        }
        /** @var LengthAwarePaginator<int, Student> $records */
        $records = $query->paginate(perPage: $this->getTableRecordsPerPage() === 'all' ? max(1, $total) : $this->getTableRecordsPerPage(),
            pageName: $this->getTablePaginationPageName(), total: $total);
        $first = $records->first();
        // Empty/out-of-range pages have no row carrying the global tab counts.
        // Only those pages need a bounded metadata fallback, never a row read.
        $metadata = $first === null ? $counts->first() : null;
        foreach ($names as $name) {
            $alias = 'tab_count_'.$name;
            $this->tabCounts[$name] = (int) ($first?->getAttribute($alias) ?? $metadata?->{$alias});
        }

        return $records->onEachSide(0);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('enrollment.all'))->badge(fn (): int => $this->tabCounts['all'] ?? 0)];
        foreach ([StudentStatus::Active, StudentStatus::Prospective] as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge(fn (): int => $this->tabCounts[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }
        if (auth()->user()?->can('view_any_charge')) {
            $tabs['owes_money'] = Tab::make(__('enrollment.owes_money'))
                ->badge(fn (): int => $this->tabCounts['owes_money'] ?? 0)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('students.id',
                    DB::query()->fromSub(app(StudentBalanceQuery::class)->withOutstanding(Student::query()), 'balances')
                        ->select('id')->where(StudentBalanceQuery::OUTSTANDING_ALIAS, '>', 0)));
        }
        $tabs['no_portal'] = Tab::make(__('enrollment.no_portal_login'))
            ->badge(fn (): int => $this->tabCounts['no_portal'] ?? 0)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('user_id'));

        return $tabs;
    }

    #[Computed]
    public function selectedStudent(): ?Student
    {
        if ($this->selectedStudentId === null) {
            return null;
        }
        $query = Student::query();
        if (auth()->user()?->can('view_any_charge')) {
            app(StudentBalanceQuery::class)->withOutstanding($query);
        }
        $student = $query->find($this->selectedStudentId);
        if (! $student || ! Gate::allows('view', $student)) {
            return null;
        }
        if (Gate::allows('viewAny', Enrollment::class)) {
            $student->load('enrollments.batch.course');
        }

        return $student;
    }

    public function table(Table $table): Table
    {
        return $table->recordUrl(null)->recordAction('selectStudent')
            ->pushRecordActions([
                Action::make('selectStudent')->label(__('enrollment.select_student'))
                    ->icon('heroicon-o-chevron-right')->iconButton()->authorize('view')
                    ->action(function (Student $record): void {
                        $this->selectedStudentId = (int) $record->getKey();
                        unset($this->selectedStudent);
                    }),
            ]);
    }

    /** @return array<Action> */
    private function detailActions(): array
    {
        return $this->selectedStudent ? [
            ...array_map(fn (Action $action): Action => $action->record($this->selectedStudent), StudentResource::portalActions()),
            Action::make('editSelected')->label(__('enrollment.edit_student'))
                ->visible(fn (): bool => StudentResource::canEdit($this->selectedStudent))
                ->url(fn (): string => StudentResource::getUrl('edit', ['record' => $this->selectedStudent])),
        ] : [];
    }

    /** @return array<string, mixed> */
    private function detailViewData(): array
    {
        $student = $this->selectedStudent;
        $jumps = [];
        if ($student !== null) {
            foreach ([ChargeResource::class => 'bills_jump', PaymentResource::class => 'receipts_jump', StudentCertificateResource::class => 'certificates_jump'] as $resource => $label) {
                if ($resource::canViewAny()) {
                    $jumps[] = ['label' => __('enrollment.'.$label), 'url' => $resource::getUrl('index', ['filters' => ['student' => ['id' => $student->getKey()]]])];
                }
            }
        }
        $canRead = Gate::allows('viewAny', Enrollment::class);

        return [
            'student' => $student, 'jumps' => $jumps, 'canReadEnrollments' => $canRead,
            'fields' => $student ? [
                __('enrollment.status') => $student->status->label(),
                __('enrollment.phone') => $student->phone ?? __('enrollment.not_recorded'),
                __('enrollment.email') => $student->email ?? __('enrollment.not_recorded'),
                __('enrollment.national_id') => $student->national_id ?? __('enrollment.not_recorded'),
                __('enrollment.date_of_birth') => $student->date_of_birth?->format('d M Y') ?? __('enrollment.not_recorded'),
                ...(auth()->user()?->can('view_any_charge') ? [__('enrollment.outstanding') => __('enrollment.money_lyd', ['amount' => $student->getAttribute(StudentBalanceQuery::OUTSTANDING_ALIAS)])] : []),
            ] : [],
            'enrollments' => $student && $canRead ? $student->enrollments->map(fn (Enrollment $record): array => [
                'batch' => $record->batch->code, 'course' => $record->batch->course->name(), 'status' => $record->status->label(),
                'color' => match ($record->status) {
                    EnrollmentStatus::Active => 'success', EnrollmentStatus::Completed => 'info', EnrollmentStatus::Withdrawn => 'danger'
                },
                'url' => BatchResource::canViewAny() && BatchResource::canView($record->batch) ? BatchResource::getUrl('view', ['record' => $record->batch]) : null,
            ])->all() : [],
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                EmbeddedTable::make()->columnSpan(['xl' => 2]),
                View::make('filament.enrollment.student-detail')
                    ->key('studentDetail')
                    ->viewData(fn (): array => $this->detailViewData())
                    ->schema([Actions::make(fn (): array => $this->detailActions())->key('actions')])
                    ->extraAttributes(['class' => 'xl:sticky xl:[inset-block-start:6rem] self-start']),
            ]),
        ]);
    }

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction — the same
     * reasoning as ListUsers: CreateAction keeps a mountable server-side create
     * handler even when ->url() is set, and that handler persists with a bare
     * $model::create($data) that never runs the create page's hooks.
     *
     * canCreate() is checked explicitly because a plain Action carries no
     * authorization of its own; without it the button renders for view-only
     * staff, who then hit a 403 on the page behind it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('enrollment.create_student'))
                ->visible(fn (): bool => StudentResource::canCreate())
                ->url(fn (): string => StudentResource::getUrl('create')),
        ];
    }
}
