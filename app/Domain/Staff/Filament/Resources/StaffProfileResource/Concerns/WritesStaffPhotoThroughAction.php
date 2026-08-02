<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\Concerns;

use App\Domain\Staff\Actions\UpdateStaffPhotoAction;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The save-hook half of StaffProfileResource's photo boundary.
 *
 * WHY getRawState() AND NOT $data
 * -------------------------------
 * `profile_photo` is dehydrated(false), so Filament EXCLUDES it from the `$data`
 * array handed to the record write. Reading it from $data would always yield
 * nothing. `$this->form->getRawState()` is the live component state and holds the
 * TemporaryUploadedFile the browser uploaded.
 *
 * WHY THE FIELD HOLDS A TemporaryUploadedFile AT ALL
 * --------------------------------------------------
 * The field is storeFiles(false), so Filament never persists it — the raw state
 * is the Livewire temporary upload, exactly what UpdateStaffPhotoAction expects.
 * The Action re-validates it (mime by content, size, dimensions) and owns both
 * the write and the deletion of any file it replaces.
 *
 * WHY A REFUSAL THROWS Halt WITH A ROLLBACK
 * -----------------------------------------
 * The record's own attributes are already written by the time afterSave() runs.
 * A photo refusal that merely reported itself would leave a partial save: the job
 * title committed, the photo change refused. EditStaffProfile sets
 * $hasDatabaseTransactions = true, and Halt::rollBackDatabaseTransaction() unwinds
 * the whole save.
 *
 * Only the three expected outcomes are caught — an authorization denial, a
 * validation failure on the image, and a storage failure the disk reported.
 * Anything else propagates; a real fault must not be dressed up as a
 * notification.
 *
 * The storage failure is expected in the same sense as the other two: the
 * private disk is configured with throw => false, so the Action turns a false
 * return into FileStorageException rather than pretending the write succeeded.
 * A full disk is an operational condition with a correct answer, not a bug.
 */
trait WritesStaffPhotoThroughAction
{
    protected function writePhotoThroughActionIfUploaded(): void
    {
        $upload = $this->rawPhotoState();

        // No new file this save — the field was left untouched. Not an error,
        // and emphatically not a reason to clear an existing photo. Removal is
        // its own affordance (DeleteStaffPhotoAction), never a silent side
        // effect of saving the rest of the form.
        if (! $upload instanceof UploadedFile) {
            return;
        }

        try {
            app(UpdateStaffPhotoAction::class)->execute(
                $this->currentActor(),
                $this->profileRecord(),
                $upload,
            );
        } catch (AuthorizationException) {
            $this->refusePhoto(__('staff.photo_refused_unauthorized'));
        } catch (ValidationException $exception) {
            $this->refusePhoto(
                $exception->validator->errors()->first() ?: __('staff.photo_invalid'),
            );
        } catch (FileStorageException) {
            /*
             * P1-T15, domain-integrity finding 3. A full or read-only disk left
             * the panel on an unexplained 500, with the rest of the save already
             * committed.
             *
             * THE ROLLBACK MATTERS MORE THAN THE WORDING. It goes through
             * refusePhoto() like the other two, so the Halt unwinds the whole
             * save; otherwise the job title commits while the photo silently
             * does not, and the administrator is left with a record they believe
             * they updated.
             *
             * Handled here rather than left to bootstrap/app.php's 503 renderer,
             * which is the wrong answer inside the panel: it would replace the
             * page with a bare status response and lose the rollback with it.
             * That renderer is the backstop for paths with no better handling.
             */
            $this->refusePhoto(__('staff.storage_unavailable'));
        }
    }

    protected function refusePhoto(string $title): never
    {
        Notification::make()
            ->title($title)
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    protected function rawPhotoState(): mixed
    {
        $state = $this->form->getRawState();
        $state = is_array($state) ? $state : $state->toArray();

        $photo = $state['profile_photo'] ?? null;

        // A FileUpload field holds an array of uploads keyed by a hash; a single
        // upload is the first (and only) element.
        if (is_array($photo)) {
            $photo = reset($photo);
        }

        return $photo;
    }

    protected function currentActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    protected function profileRecord(): StaffProfile
    {
        /** @var StaffProfile $record */
        $record = $this->record;

        return $record;
    }
}
