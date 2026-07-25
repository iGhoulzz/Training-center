<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages;

use App\Domain\Staff\Actions\DeleteStaffPhotoAction;
use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Concerns\WritesStaffPhotoThroughAction;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Editing a staff profile.
 *
 * The record's own attributes are written by Filament; the photo is not — it is
 * dehydrated(false)/storeFiles(false) and applied in afterSave() through
 * UpdateStaffPhotoAction, which authorizes the acting user and owns the deletion
 * of any file it replaces. See the WritesStaffPhotoThroughAction concern.
 *
 * Access is gated by EditRecord::authorizeAccess(): 403 unless the actor passes
 * StaffProfilePolicy::update().
 */
class EditStaffProfile extends EditRecord
{
    use WritesStaffPhotoThroughAction;

    protected static string $resource = StaffProfileResource::class;

    /**
     * MUST be ?bool. Filament declares it ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions; narrowing to bool is a
     * fatal type error. It is true so an afterSave() photo refusal rolls the
     * whole save back rather than leaving the attribute write committed.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            // Removing the photo is its own action, gated on update: it clears
            // the column and schedules the file for deletion through
            // DeleteStaffPhotoAction. Saving the form never clears a photo, so
            // this is the only way to remove one.
            Action::make('deletePhoto')
                ->label(__('staff.delete_photo'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->authorize('update')
                ->visible(fn (StaffProfile $record): bool => is_string($record->profile_photo_path)
                    && $record->profile_photo_path !== '')
                ->action(function (StaffProfile $record): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(DeleteStaffPhotoAction::class)->execute($actor, $record);
                }),

            StaffProfileResource::deleteAction()
                ->successRedirectUrl(fn (): string => StaffProfileResource::getUrl('index')),
        ];
    }

    protected function afterSave(): void
    {
        $this->writePhotoThroughActionIfUploaded();
    }
}
