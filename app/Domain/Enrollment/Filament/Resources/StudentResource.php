<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Domain\Staff\Actions\IssuePortalCredentialAction;
use App\Domain\Staff\Actions\ResetPortalCredentialAction;
use App\Domain\Staff\Exceptions\EmailAlreadyRegisteredException;
use App\Domain\Staff\Exceptions\ProtectedAccountException;
use App\Domain\Staff\Exceptions\StudentHasNoEmailException;
use App\Domain\Staff\Exceptions\StudentHasNoPortalAccountException;
use App\Domain\Staff\Exceptions\StudentHasPortalAccountException;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The student register (P1-T07).
 *
 * FULL PAGES, NOT MODAL ACTIONS
 * -----------------------------
 * List, create, view and edit are all real pages. CreateStudent hands its insert
 * to CreateWithIdentifierCodeAction through handleRecordCreation() (P35-T09), and
 * Filament's modal CreateAction/EditAction persist with a bare create()/update()
 * that never runs a page method — a modal create would skip code generation and
 * reach the NOT NULL column with nothing. The list page's create button is a
 * plain link Action for the same reason as UserResource's: CreateAction keeps a
 * mountable server-side handler even when ->url() is set.
 *
 * NO BULK ACTIONS
 * ---------------
 * Filament authorizes a bulk action once against the *Any policy method and
 * never consults the per-record one. StudentPolicy::deleteAny() is written out
 * and refuses, which is what stops a bulk delete added later inheriting a rule
 * nobody decided. Delete students one at a time, from the edit page.
 *
 * The refusal has to be WRITTEN, not merely absent. Filament resolves a missing
 * policy method to Response::allow(); an earlier version of this comment said
 * omission would "fail closed", which is true of the Gate and false of the panel.
 *
 * user_id IS NOT ON THE FORM
 * --------------------------
 * Linking a student to a portal login is phase 3's job and belongs to whatever
 * flow issues that account. Exposing the column here would let an administrator
 * point a student row at an arbitrary user account by hand.
 */
class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $recordTitleAttribute = 'student_code';

    public static function getModelLabel(): string
    {
        return __('enrollment.student');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrollment.students');
    }

    public static function getNavigationLabel(): string
    {
        return __('enrollment.students');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->extraAttributes(['class' => 'gap-4 items-start'])->components([
            Group::make()->columnSpan(['lg' => 2])
                ->extraAttributes(['class' => 'gap-[14px] [&_.fi-section-content]:p-[18px] [&_.fi-section-header]:px-[18px] [&_.fi-section-header]:py-[18px] [&_.fi-section-header-heading]:text-[14px] [&_.fi-section-header-description]:text-[11.5px] [&_.fi-fo-field-label-content]:text-[12px] [&_.fi-input]:text-[13.5px] [&_.fi-input-wrp]:rounded-[9px] [&_.fi-sc-has-gap]:gap-[13px]'])
                ->schema([
                    Section::make(__('enrollment.identity'))->description(__('enrollment.identity_hint'))->columns(2)->schema([
                        TextInput::make('student_code')
                            ->live(onBlur: true)
                            ->label(__('enrollment.student_code'))
                            // Blank on create is generated by CreateWithIdentifierCodeAction
                            // (P35-T09); a typed value stands, for importing an existing
                            // centre's records. An edit must keep a value: the column is NOT NULL.
                            ->required(fn (string $operation): bool => $operation === 'edit')
                            ->placeholder(fn (string $operation): ?string => $operation === 'create'
                                ? __('enrollment.identifier_code_hint')
                                : null)
                            ->maxLength(30)
                            // The code is quoted at the desk and printed on paperwork, so a
                            // duplicate is a real-world ambiguity, not just a broken index.
                            ->unique(ignoreRecord: true),

                        Select::make('status')
                            ->live()
                            ->label(__('enrollment.status'))
                            ->options(fn (): array => collect(StudentStatus::cases())
                                ->mapWithKeys(fn (StudentStatus $case): array => [$case->value => $case->label()])
                                ->all())
                            ->default(StudentStatus::Prospective->value)
                            ->required(),

                        TextInput::make('first_name')
                            ->live(onBlur: true)
                            ->label(__('enrollment.first_name'))
                            ->required()
                            ->maxLength(100),

                        TextInput::make('last_name')
                            ->live(onBlur: true)
                            ->label(__('enrollment.last_name'))
                            ->required()
                            ->maxLength(100),
                    ]),

                    Section::make(__('enrollment.contact'))->description(__('enrollment.contact_hint'))->columns(2)->schema([

                        TextInput::make('email')
                            ->label(__('enrollment.email'))
                            ->email()
                            ->live(onBlur: true)
                            ->helperText(__('enrollment.no_email_no_portal'))
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label(__('enrollment.phone'))
                            ->tel()
                            ->maxLength(30),

                        Textarea::make('address')->label(__('enrollment.address'))->rows(3)->columnSpanFull(),
                    ]),
                    Section::make(__('enrollment.records'))->description(__('enrollment.records_hint'))->columns(2)->schema([

                        TextInput::make('national_id')
                            ->label(__('enrollment.national_id'))
                            ->maxLength(50),

                        DatePicker::make('date_of_birth')
                            ->label(__('enrollment.date_of_birth'))
                            ->maxDate(now()),

                        Select::make('gender')
                            ->label(__('enrollment.gender'))
                            ->options([
                                'male' => __('enrollment.gender_male'),
                                'female' => __('enrollment.gender_female'),
                            ]),

                        Textarea::make('notes')
                            ->label(__('enrollment.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                ]),
            View::make('filament.enrollment.create-consequences')
                ->key('preview')
                ->schema(fn ($livewire): array => $livewire instanceof CreateRecord ? [$livewire->getFormActionsContentComponent()] : [])
                ->viewData(function (Get $get, ?Student $record): array {
                    $surnameMatch = null;
                    if (filled($get('last_name')) && auth()->user()->can('view_any_student') && auth()->user()->can('view_student')) {
                        $surnameMatch = Student::query()->where('last_name', (string) $get('last_name'))
                            ->when($record, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))->orderBy('id')->value('student_code');
                    }

                    return [
                        'heading' => __('enrollment.student_preview'),
                        'name' => trim((string) $get('first_name').' '.(string) $get('last_name')) ?: __('enrollment.new_student'),
                        'secondary' => filled($get('student_code')) ? $get('student_code') : __('enrollment.assigned_on_save'),
                        'facts' => [__('enrollment.status') => StudentStatus::tryFrom((string) $get('status'))?->label() ?? __('enrollment.not_recorded')],
                        'notices' => [
                            ['tone' => filled($get('email')) ? 'success' : 'warning', 'text' => filled($get('email')) ? __('enrollment.email_allows_portal') : __('enrollment.no_email_no_portal')],
                            ['tone' => 'neutral', 'text' => __('enrollment.identifier_code_hint')],
                            ...($surnameMatch !== null ? [['tone' => 'warning', 'text' => __('enrollment.surname_reminder', ['code' => $surnameMatch])]] : []),
                        ],
                    ];
                })->visible(fn (string $operation): bool => $operation !== 'view'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Staff without Finance read access must not receive balance data.
            ->modifyQueryUsing(fn (Builder $query): Builder => auth()->user()?->can('view_any_charge')
                ? app(StudentBalanceQuery::class)->withOutstanding($query)
                : $query)
            ->filters([
                Filter::make('course')->label(__('enrollment.course'))
                    ->schema([TextInput::make('id')->label(__('enrollment.course'))->integer()->minValue(1)])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['id'] ?? null)) {
                            return $query;
                        }
                        $id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                        return $id !== false && (auth()->user()?->can('view_any_enrollment') ?? false)
                            ? $query->whereHas('enrollments', fn (Builder $enrollment): Builder => $enrollment
                                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
                                ->whereHas('batch', fn (Builder $batch): Builder => $batch->where('course_id', $id)))
                            : $query->whereRaw('1 = 0');
                    }),
            ])
            ->columns([
                Grid::make(['default' => 1, 'md' => 3])->extraAttributes([
                    'class' => 'gap-2 md:gap-3 md:grid-cols-[minmax(118px,1.6fr)_minmax(84px,.9fr)_minmax(66px,.8fr)] [&>*]:min-w-0',
                ])->schema([
                    Stack::make([
                        ViewColumn::make('identity')->view('filament.enrollment.columns.person')
                            ->searchable(['first_name', 'last_name', 'student_code', 'phone'])
                            ->viewData(fn (Student $record): array => [
                                'name' => $record->full_name, 'identity' => $record->getKey(),
                                'secondary' => $record->student_code.' · '.($record->phone ?? __('enrollment.no_phone')), 'url' => null,
                            ]),
                        TextColumn::make('student_code')
                            ->label(__('enrollment.student_code'))
                            ->hidden()
                            ->searchable()
                            ->sortable(),

                        // full_name is an accessor, not a column, so the search has to
                        // name the two real columns behind it — and it cannot be
                        // sorted at all. Order by last name instead; that is what the
                        // (last_name, first_name) index exists for.
                        TextColumn::make('full_name')
                            ->label(__('enrollment.full_name'))
                            ->hidden()
                            ->searchable(['first_name', 'last_name'])
                            ->weight('semibold'),

                        TextColumn::make('phone')
                            ->label(__('enrollment.phone'))
                            ->hidden()
                            ->searchable()
                            ->placeholder(__('enrollment.no_phone')),
                    ]),

                    TextColumn::make('status')
                        ->label(__('enrollment.status'))
                        ->badge()
                        ->formatStateUsing(fn (StudentStatus $state): string => $state->label())
                        ->color(fn (StudentStatus $state): string => match ($state) {
                            StudentStatus::Active => 'success',
                            StudentStatus::Graduated => 'info',
                            default => 'gray',
                        })
                        ->sortable(),

                    TextColumn::make(StudentBalanceQuery::OUTSTANDING_ALIAS)
                        ->label(__('enrollment.outstanding'))
                        ->visible(fn (): bool => auth()->user()?->can('view_any_charge') ?? false)
                        ->formatStateUsing(fn (string $state): string => __('enrollment.money_lyd', ['amount' => $state]))
                        ->extraAttributes(['class' => 'whitespace-nowrap tabular-nums text-[12.5px]'])
                        ->color('warning'),
                ]),
            ])
            // Surname order: the register is a list of people, and the desk
            // looks someone up by name far more often than by when they were
            // added. The composite index serves this sort directly.
            ->defaultSort('last_name')
            // Delete belongs on the row, not only on the edit page.
            //
            // EditRecord::authorizeAccess() requires update_student to open the
            // page at all, so an actor holding delete_student WITHOUT
            // update_student could never reach a delete action placed there —
            // the grant would be unreachable, and the two permissions are
            // separate on purpose. From the table it is reachable with view +
            // delete alone.
            //
            // authorize() rather than visible(): visible() is a UX affordance
            // that a crafted Livewire mount ignores, whereas authorize() runs
            // StudentPolicy::delete() against this record on the server.
            ->recordActions([
                ActionGroup::make([
                    ...self::portalActions(),
                    DeleteAction::make()->authorize('delete'),
                ])->iconButton()->dropdownTeleport(),
            ]);
    }

    /** @return array<Action> */
    public static function portalActions(): array
    {
        return [
            Action::make('issuePortalCredential')
                ->label(__('credentials.issue'))
                ->icon(Heroicon::OutlinedKey)
                ->requiresConfirmation()
                ->visible(fn (Student $record): bool => $record->user_id === null
                    && (auth()->user()?->can('issue_portal_credential') ?? false))
                // A visible disabled control tells staff why this student
                // cannot receive a login without letting a submit fail.
                ->disabled(fn (Student $record): bool => blank($record->email))
                ->tooltip(fn (Student $record): ?string => blank($record->email)
                    ? __('credentials.student_has_no_email')
                    : null)
                ->action(function (Student $record): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        $plain = app(IssuePortalCredentialAction::class)->execute($actor, $record);
                    } catch (AuthorizationException) {
                        Notification::make()
                            ->title(__('credentials.unauthorized'))
                            ->danger()
                            ->send();

                        return;
                    } catch (StudentHasPortalAccountException|StudentHasNoEmailException|EmailAlreadyRegisteredException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('credentials.issued'))
                        ->body($plain)
                        ->persistent()
                        ->warning()
                        ->send();
                }),

            Action::make('resetPortalCredential')
                ->label(__('credentials.reset'))
                ->icon(Heroicon::OutlinedKey)
                ->requiresConfirmation()
                ->visible(fn (Student $record): bool => $record->user_id !== null
                    && (auth()->user()?->can('reset_portal_credential') ?? false))
                ->action(function (Student $record): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        $plain = app(ResetPortalCredentialAction::class)->execute($actor, $record);
                    } catch (AuthorizationException) {
                        Notification::make()
                            ->title(__('credentials.unauthorized'))
                            ->danger()
                            ->send();

                        return;
                    } catch (StudentHasNoPortalAccountException|ProtectedAccountException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('credentials.reset_complete'))
                        ->body($plain)
                        ->persistent()
                        ->warning()
                        ->send();
                }),

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'view' => ViewStudent::route('/{record}'),
            'edit' => EditStudent::route('/{record}/edit'),
        ];
    }
}
