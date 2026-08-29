<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources;

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\IssuePortalCredentialAction;
use App\Domain\Staff\Actions\ResetPortalCredentialAction;
use App\Domain\Staff\Exceptions\EmailAlreadyRegisteredException;
use App\Domain\Staff\Exceptions\ProtectedAccountException;
use App\Domain\Staff\Exceptions\StudentHasNoEmailException;
use App\Domain\Staff\Exceptions\StudentHasPortalAccountException;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The student register (P1-T07).
 *
 * FULL PAGES, NOT MODAL ACTIONS
 * -----------------------------
 * List, create, view and edit are all real pages. Nothing here has a save hook
 * today, but Filament's modal CreateAction/EditAction persist with a bare
 * create()/update() that never runs a page hook, so a resource built on modals
 * quietly breaks the moment one is added. The list page's create button is a
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
        return $schema->components([
            TextInput::make('student_code')
                ->label(__('enrollment.student_code'))
                ->required()
                ->maxLength(30)
                // The code is quoted at the desk and printed on paperwork, so a
                // duplicate is a real-world ambiguity, not just a broken index.
                ->unique(ignoreRecord: true),

            Select::make('status')
                ->label(__('enrollment.status'))
                ->options(fn (): array => collect(StudentStatus::cases())
                    ->mapWithKeys(fn (StudentStatus $case): array => [$case->value => $case->label()])
                    ->all())
                ->default(StudentStatus::Prospective->value)
                ->required(),

            TextInput::make('first_name')
                ->label(__('enrollment.first_name'))
                ->required()
                ->maxLength(100),

            TextInput::make('last_name')
                ->label(__('enrollment.last_name'))
                ->required()
                ->maxLength(100),

            TextInput::make('email')
                ->label(__('enrollment.email'))
                ->email()
                ->maxLength(255),

            TextInput::make('phone')
                ->label(__('enrollment.phone'))
                ->tel()
                ->maxLength(30),

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

            Textarea::make('address')
                ->label(__('enrollment.address'))
                ->rows(3)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label(__('enrollment.notes'))
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_code')
                    ->label(__('enrollment.student_code'))
                    ->searchable()
                    ->sortable(),

                // full_name is an accessor, not a column, so the search has to
                // name the two real columns behind it — and it cannot be
                // sorted at all. Order by last name instead; that is what the
                // (last_name, first_name) index exists for.
                TextColumn::make('full_name')
                    ->label(__('enrollment.full_name'))
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('phone')
                    ->label(__('enrollment.phone'))
                    ->searchable()
                    ->placeholder(__('enrollment.no_phone')),

                TextColumn::make('status')
                    ->label(__('enrollment.status'))
                    ->badge()
                    ->formatStateUsing(fn (StudentStatus $state): string => $state->label())
                    ->sortable(),
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
                        } catch (StudentHasPortalAccountException|ProtectedAccountException $exception) {
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

                DeleteAction::make()
                    ->authorize('delete'),
            ]);
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
