<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Actions\DeleteStaffProfileAction;
use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\CreateStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\EditStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ListStaffProfiles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ViewStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\RelationManagers\CertificatesRelationManager;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff register (P1-T06b).
 *
 * FULL PAGES, NOT MODAL ACTIONS
 * -----------------------------
 * List, create, view and edit are real pages. Filament's modal
 * CreateAction/EditAction persist with a bare create()/update() that never runs a
 * page save hook, and this resource has save hooks that own the photo write. The
 * list page's "new" button is therefore a plain link Action, not a CreateAction —
 * CreateAction keeps a mountable server-side handler even when ->url() is set.
 *
 * NO BULK ACTIONS, HERE OR ON THE RELATION MANAGER
 * ------------------------------------------------
 * Filament authorizes a bulk action once against the *Any policy method and
 * never consults the per-record one. A bulk profile delete would skip
 * DeleteStaffProfileAction entirely: no per-profile certificate-grant check, and
 * no pending_file_deletions receipts, so every certificate file the selection
 * owned would be orphaned on disk. Delete one at a time, where the Action runs.
 *
 * THE PHOTO IS NOT A MODEL-BOUND FIELD
 * ------------------------------------
 * `profile_photo` is dehydrated(false) AND storeFiles(false). Both are needed:
 *
 *   - Without storeFiles(false), Filament writes the upload to a disk itself
 *     during dehydration, choosing the path and leaving the old file behind. The
 *     Action must own that, because replacing a photo has to schedule the file it
 *     replaced for deletion.
 *   - Without dehydrated(false), the temporary upload would be handed to the
 *     record write as an attribute value.
 *
 * The pages read the live form state and call UpdateStaffPhotoAction. See the
 * WritesStaffPhotoThroughAction concern.
 *
 * The field appears on the edit page only. UpdateStaffPhotoAction authorizes
 * `update` on the profile, so offering it at creation would silently require
 * update_staff_profile to complete a create — two separate grants welded
 * together by a form field.
 *
 * The generic tag is load-bearing: Filament's Resource is generic over its model
 * and defaults to Model, so without it getEloquentQuery() infers Builder<Model>
 * and static analysis fails. The class name is written in full so Pint's
 * phpdoc_types fixer cannot lowercase a bare `Resource` into the `resource`
 * pseudo-type. See BatchResource for the same note.
 *
 * @extends \Filament\Resources\Resource<StaffProfile>
 */
class StaffProfileResource extends Resource
{
    protected static ?string $model = StaffProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'job_title';

    public static function getModelLabel(): string
    {
        return __('staff.staff_profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.staff_profiles');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.staff_profiles');
    }

    /**
     * @return Builder<StaffProfile>
     */
    public static function getEloquentQuery(): Builder
    {
        // initials() reads the linked account's name, and the table renders it
        // for every row. Without this the register is one query per staff member.
        return parent::getEloquentQuery()->with('user');
    }

    /**
     * The read-only view schema (P1-T15, group 2 review).
     *
     * WITHOUT THIS, THE VIEW PAGE RENDERED THE EDIT FORM — AND LEAKED THE ROSTER.
     *
     * ViewStaffProfile declared no schema and this resource had no infolist(), so
     * Filament fell back to form(). At the time that form opened with a preloaded
     * user Select: every account name in the system was rendered as options to
     * anybody who could reach the page.
     *
     * Proven with a synthetic role holding view_any_staff_profile,
     * view_staff_profile and access_admin_panel and NO user permission — a shape
     * no seeded role has, which is why eleven tasks of testing never showed it.
     * The page returned 200 and the canary account name was in the HTML.
     *
     * THIS IS A SEPARATE SCHEMA, NOT THE FORM WITH FIELDS HIDDEN.
     *
     * Hiding or disabling the Select would leave the options query in the render
     * path, one conditional away from disclosure again, and would keep view and
     * edit coupled so that any future form field is exposed here by default. A
     * view page shows what this profile IS. It never offers a way to choose a
     * different owner, so it has no reason to know that any other account exists.
     *
     * The owner is shown as text from the profile's OWN relation — one name, the
     * one the viewer already asked to see.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.name')
                ->label(__('staff.user')),

            TextEntry::make('job_title')
                ->label(__('staff.job_title'))
                ->placeholder(__('staff.no_job_title')),

            TextEntry::make('employment_type')
                ->label(__('staff.employment_type'))
                ->formatStateUsing(fn (EmploymentType|string|null $state): string => $state instanceof EmploymentType
                    ? $state->label()
                    : (string) $state),

            TextEntry::make('phone')
                ->label(__('staff.phone'))
                ->placeholder(__('enrollment.no_phone')),

            TextEntry::make('hire_date')
                ->label(__('staff.hire_date'))
                ->date()
                ->placeholder(__('staff.no_hire_date')),

            TextEntry::make('qualifications')
                ->label(__('staff.qualifications'))
                ->placeholder(__('activity.empty_value'))
                ->columnSpanFull(),

            ImageEntry::make('profile_photo_path')
                ->label(__('staff.profile_photo'))
                // The same authorized route the table uses. The private disk has
                // no URL, so the photo is never addressable except through the
                // controller that re-authorizes it.
                // Nullable record type on purpose: Filament evaluates these
                // outside a row context too — inspecting the schema, for one —
                // and a non-nullable hint turns that into a TypeError.
                ->getStateUsing(fn (?StaffProfile $record): ?string => $record?->profile_photo_path === null
                    ? null
                    : route('staff.profiles.photo', ['profile' => $record]))
                ->visible(fn (?StaffProfile $record): bool => $record?->profile_photo_path !== null)
                ->columnSpanFull(),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label(__('staff.user'))
                ->options(fn (): array => self::searchUsers(''))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::searchUsers($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::userOptionLabel($value))
                ->required()
                /*
                 * An employment profile owns its photo and credentials. Letting
                 * a generic edit change user_id silently transfers those private
                 * documents to another person. Reassignment, if ever needed,
                 * belongs in an explicit Action that states that consequence.
                 */
                ->disabled(fn (?StaffProfile $record): bool => $record !== null)
                ->dehydrated(fn (?StaffProfile $record): bool => $record === null)
                // One employment record per account, enforced by a unique index.
                // Without this the form turns a duplicate into a 500.
                ->unique(ignoreRecord: true),

            TextInput::make('job_title')
                ->label(__('staff.job_title'))
                ->maxLength(120),

            Select::make('employment_type')
                ->label(__('staff.employment_type'))
                ->options(fn (): array => collect(EmploymentType::cases())
                    ->mapWithKeys(fn (EmploymentType $case): array => [$case->value => $case->label()])
                    ->all())
                ->default(EmploymentType::Administrative->value)
                ->required(),

            TextInput::make('phone')
                ->label(__('staff.phone'))
                ->tel()
                ->maxLength(30),

            DatePicker::make('hire_date')
                ->label(__('staff.hire_date'))
                ->maxDate(now()),

            Textarea::make('qualifications')
                ->label(__('staff.qualifications'))
                ->rows(3)
                ->columnSpanFull(),

            FileUpload::make('profile_photo')
                ->label(__('staff.profile_photo'))
                // See the class docblock: neither of these is optional.
                ->dehydrated(false)
                ->storeFiles(false)
                ->image()
                ->acceptedFileTypes(UpdateStaffPhotoAction::ACCEPTED_MIME_TYPES)
                ->maxSize(UpdateStaffPhotoAction::MAX_KILOBYTES)
                // Edit only. UpdateStaffPhotoAction authorizes `update`, which an
                // actor holding only create_staff_profile does not have.
                ->visible(fn (?StaffProfile $record): bool => $record instanceof StaffProfile)
                ->helperText(__('staff.profile_photo_help'))
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    public static function searchUsers(string $search): array
    {
        return User::query()
            ->select(['id', 'name'])
            ->where('name', 'like', "%{$search}%")
            ->orderBy('name')
            ->limit(25)
            ->pluck('name', 'id')
            ->all();
    }

    public static function userOptionLabel(mixed $value): ?string
    {
        return User::withTrashed()
            ->whereKey($value)
            ->value('name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * A real photo is served through a per-request policy check.
                 * The private disk has no URL; feeding its path to ImageColumn
                 * would make Filament fall back to Storage::url(), which is both
                 * wrong and outside the authorization boundary.
                 */
                ImageColumn::make('profile_photo_path')
                    ->label(__('staff.profile_photo'))
                    ->state(fn (StaffProfile $record): ?string => (
                        is_string($record->profile_photo_path)
                        && $record->profile_photo_path !== ''
                        && static::canView($record)
                    ) ? route('staff.profiles.photo', $record) : null)
                    ->placeholder(fn (StaffProfile $record): string => $record->initials())
                    ->alt(fn (StaffProfile $record): string => $record->user->name)
                    ->circular(),

                TextColumn::make('user.name')
                    ->label(__('staff.name'))
                    ->searchable()
                    ->sortable()
                    // A departed instructor keeps their profile; the relation is
                    // withTrashed() so the row still says whose it is.
                    ->placeholder(__('staff.no_account')),

                TextColumn::make('job_title')
                    ->label(__('staff.job_title'))
                    ->searchable()
                    ->placeholder(__('staff.no_job_title')),

                TextColumn::make('employment_type')
                    ->label(__('staff.employment_type'))
                    ->badge()
                    ->formatStateUsing(fn (EmploymentType $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('hire_date')
                    ->label(__('staff.hire_date'))
                    ->date()
                    ->placeholder(__('staff.no_hire_date'))
                    ->sortable(),

                TextColumn::make('certificates_count')
                    ->label(__('staff.certificates'))
                    ->counts('certificates'),
            ])
            ->defaultSort('id')
            ->recordUrl(fn (StaffProfile $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                static::deleteAction(),
            ]);
    }

    /**
     * The one delete affordance, shared by the table row and the edit page.
     *
     * ON THE TABLE ROW, NOT ONLY THE EDIT PAGE
     * ----------------------------------------
     * EditRecord::authorizeAccess() demands update_staff_profile to open the edit
     * page at all, so a delete action living only there is unreachable for an
     * actor holding delete_staff_profile without update_staff_profile — and those
     * are separate grants on purpose. From the table it needs only view + delete.
     *
     * authorize(), not visible(): visible() is a UX affordance a crafted Livewire
     * mount ignores, whereas authorize('delete') runs StaffProfilePolicy::delete()
     * against this record on the server and makes the action unmountable.
     *
     * using() replaces DeleteAction's built-in $record->delete() with
     * DeleteStaffProfileAction. That is not a nicety: the plain delete cascades
     * the certificate ROWS away and leaves every one of their files on disk, and
     * it skips the check that an actor destroying certificates by cascade holds
     * delete_staff_certificate too.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize('delete')
            ->using(function (StaffProfile $record): bool {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(DeleteStaffProfileAction::class)->execute($actor, $record);
                } catch (AuthorizationException) {
                    // Reached when the profile owns certificates the actor may
                    // not delete: the profile grant passed, the cascade did not.
                    Notification::make()
                        ->title(__('staff.profile_delete_refused_certificates'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return false;
                }

                return true;
            });
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            CertificatesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStaffProfiles::route('/'),
            'create' => CreateStaffProfile::route('/create'),
            'view' => ViewStaffProfile::route('/{record}'),
            'edit' => EditStaffProfile::route('/{record}/edit'),
        ];
    }
}
