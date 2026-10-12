<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Services\StudentBalanceQuery;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('enrollment.all'))];
        foreach ([StudentStatus::Active, StudentStatus::Prospective] as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }
        if (auth()->user()?->can('view_any_charge')) {
            $tabs['owes_money'] = Tab::make(__('enrollment.owes_money'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('students.id',
                    DB::query()->fromSub(app(StudentBalanceQuery::class)->withOutstanding(Student::query()), 'balances')
                        ->select('id')->where(StudentBalanceQuery::OUTSTANDING_ALIAS, '>', 0)));
        }
        $tabs['no_portal'] = Tab::make(__('enrollment.no_portal_login'))
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
                    ->icon('heroicon-o-chevron-right')->authorize('view')
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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                EmbeddedTable::make()->columnSpan(['xl' => 2]),
                Section::make(fn (): string => $this->selectedStudent->full_name ?? __('enrollment.select_student'))
                    ->key('studentDetail')
                    ->description(fn (): string => $this->selectedStudent->student_code ?? __('enrollment.select_student_hint'))
                    ->schema([
                        TextEntry::make('selectedStatus')->label(__('enrollment.status'))->badge()
                            ->state(fn (): ?string => $this->selectedStudent?->status->label()),
                        ...collect(['phone', 'email', 'national_id'])
                            ->map(fn (string $field): TextEntry => TextEntry::make('selected_'.$field)
                                ->label(__('enrollment.'.$field))
                                ->state(fn (): mixed => $this->selectedStudent?->{$field})
                                ->placeholder(__('enrollment.not_recorded')))->all(),
                        TextEntry::make('selectedBirthDate')->label(__('enrollment.date_of_birth'))
                            ->state(fn (): mixed => $this->selectedStudent?->date_of_birth)->date()
                            ->placeholder(__('enrollment.not_recorded')),
                        TextEntry::make('selectedOutstanding')->label(__('enrollment.outstanding'))
                            ->state(fn (): ?string => $this->selectedStudent ? __('enrollment.money_lyd', [
                                'amount' => $this->selectedStudent->getAttribute(StudentBalanceQuery::OUTSTANDING_ALIAS),
                            ]) : null)
                            ->visible(fn (): bool => auth()->user()?->can('view_any_charge') ?? false),
                        RepeatableEntry::make('selectedEnrollments')->label(__('enrollment.enrollments'))
                            ->state(fn (): mixed => $this->selectedStudent->enrollments ?? [])
                            ->visible(fn (): bool => Gate::allows('viewAny', Enrollment::class))
                            ->schema([
                                TextEntry::make('batch.code')->label(__('enrollment.batch'))
                                    ->url(fn (Enrollment $record): ?string => BatchResource::canViewAny() && BatchResource::canView($record->batch)
                                        ? BatchResource::getUrl('view', ['record' => $record->batch]) : null),
                                TextEntry::make('status')->label(__('enrollment.status'))
                                    ->formatStateUsing(fn (EnrollmentStatus $state): string => $state->label())->badge(),
                            ]),
                    ])
                    ->footerActions([fn (): array => $this->detailActions()])
                    ->registerActions([fn (): array => $this->detailActions()])
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
