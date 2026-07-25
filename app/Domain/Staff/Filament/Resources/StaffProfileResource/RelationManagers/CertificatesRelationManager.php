<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\RelationManagers;

use App\Domain\Staff\Actions\DeleteStaffCertificateAction;
use App\Domain\Staff\Actions\UploadStaffCertificateAction;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * The certificates listed under a staff profile.
 *
 * SEPARATELY PERMISSIONED FROM THE PROFILE
 * ----------------------------------------
 * The relation manager authorizes its own visibility through
 * StaffCertificatePolicy::viewAny() (RelationManager::canViewForRecord →
 * authorize('viewAny', StaffCertificate)). So an actor holding
 * view_staff_profile WITHOUT view_any_staff_certificate opens the profile and
 * never sees this list — a job title and a scanned national ID stay two grants,
 * which is the whole reason certificates carry their own permission set.
 *
 * EVERY WRITE ROUTES THROUGH AN ACTION
 * ------------------------------------
 * Create calls UploadStaffCertificateAction; delete calls
 * DeleteStaffCertificateAction. Both use ->using() to fully replace Filament's
 * built-in persistence, so there is no bare $relationship->create() or
 * $record->delete() — the same boundary UserResource applies to its DeleteAction.
 * The plain delete would leave the certificate's bytes on disk; the plain create
 * would let the client choose disk/path/original_filename.
 *
 * disk, path, and original_filename ARE NOT FORM FIELDS. The upload Action
 * derives all three from the UploadedFile. The form offers only title, dates,
 * and the file itself.
 *
 * NO BULK ACTIONS
 * ---------------
 * None is registered, and StaffCertificatePolicy::deleteAny() gates nothing here
 * because there is no bulk delete to authorize. A bulk delete would call the
 * *Any policy once and skip every per-record certificate check and every
 * pending_file_deletions receipt, orphaning files.
 */
class CertificatesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificates';

    protected static ?string $relatedResource = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('staff.certificates');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('staff.certificate_title'))
                ->required()
                ->maxLength(200),

            DatePicker::make('issued_on')
                ->label(__('staff.issued_on'))
                ->maxDate(now()),

            DatePicker::make('expires_on')
                ->label(__('staff.expires_on'))
                // A credential cannot lapse before it was issued; the Action
                // re-checks this server-side regardless.
                ->afterOrEqual('issued_on'),

            FileUpload::make('certificate_file')
                ->label(__('staff.certificate_file'))
                ->required()
                // storeFiles(false): the upload stays a Livewire temporary file
                // and UploadStaffCertificateAction owns writing it to the private
                // disk under a generated name. Without this, Filament would store
                // it itself, choosing the path — the exact thing the Action must
                // control.
                ->storeFiles(false)
                ->acceptedFileTypes(UploadStaffCertificateAction::ACCEPTED_MIME_TYPES)
                ->maxSize(UploadStaffCertificateAction::MAX_KILOBYTES)
                ->helperText(__('staff.certificate_file_help')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('staff.certificate_title'))
                    ->searchable(),

                TextColumn::make('issued_on')
                    ->label(__('staff.issued_on'))
                    ->date()
                    ->placeholder(__('staff.no_issue_date')),

                TextColumn::make('expires_on')
                    ->label(__('staff.expires_on'))
                    ->date()
                    ->placeholder(__('staff.never_expires')),

                IconColumn::make('is_expired')
                    ->label(__('staff.expired'))
                    ->state(fn (StaffCertificate $record): bool => $record->isExpired())
                    ->boolean(),
            ])
            ->headerActions([
                static::uploadAction(),
            ])
            ->recordActions([
                static::downloadAction(),
                static::certificateDeleteAction(),
            ]);
        // No bulk actions, deliberately. See the class docblock.
    }

    /**
     * The upload button.
     *
     * A CreateAction whose persistence is fully replaced by ->using(), so the
     * built-in $relationship->create($data) never runs. The uploaded file is read
     * from the action data (storeFiles(false) leaves it a TemporaryUploadedFile),
     * and the Action derives disk/path/original_filename from it — none is a form
     * field, so none can be set by the client.
     */
    public static function uploadAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('staff.upload_certificate'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('staff.upload_certificate'))
            ->using(function (array $data, CertificatesRelationManager $livewire): StaffCertificate {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var StaffProfile $profile */
                $profile = $livewire->getOwnerRecord();

                return app(UploadStaffCertificateAction::class)->execute(
                    $actor,
                    $profile,
                    self::uploadedFileFrom($data),
                    [
                        'title' => $data['title'] ?? null,
                        'issued_on' => $data['issued_on'] ?? null,
                        'expires_on' => $data['expires_on'] ?? null,
                    ],
                );
            });
    }

    /**
     * A link to the policy-authorized download route.
     *
     * authorize('view') hides and disables the action for an actor without
     * view_staff_certificate; the route re-checks the same policy per request, so
     * the visibility gate is UX and the route is the boundary.
     */
    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('staff.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize('view')
            ->url(fn (StaffCertificate $record): string => route('staff.certificates.download', $record))
            ->openUrlInNewTab();
    }

    /**
     * Delete a certificate through DeleteStaffCertificateAction.
     *
     * authorize('delete') runs StaffCertificatePolicy::delete() on the server, so
     * an actor holding create without delete cannot mount it. using() replaces the
     * built-in $record->delete(), which would leave the file on disk.
     */
    public static function certificateDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize('delete')
            ->using(function (StaffCertificate $record): bool {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(DeleteStaffCertificateAction::class)->execute($actor, $record);
                } catch (AuthorizationException) {
                    Notification::make()
                        ->title(__('staff.certificate_delete_refused'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return false;
                }

                return true;
            });
    }

    /**
     * Pull the single uploaded file out of the FileUpload field's state.
     *
     * A FileUpload holds an array keyed by upload hash; a single upload is its
     * one element. storeFiles(false) keeps it a TemporaryUploadedFile (an
     * UploadedFile subclass), which is exactly what the Action validates and
     * stores.
     *
     * @param  array<string, mixed>  $data
     */
    private static function uploadedFileFrom(array $data): UploadedFile
    {
        $file = $data['certificate_file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        if (! $file instanceof UploadedFile) {
            // The field is required and validated by Filament before this runs,
            // so a missing file here is a wiring fault, not user input.
            throw new \RuntimeException('Certificate upload reached the Action without a file.');
        }

        return $file;
    }
}
