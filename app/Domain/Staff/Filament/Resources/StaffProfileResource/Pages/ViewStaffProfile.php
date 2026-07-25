<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages;

use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use App\Domain\Staff\Models\StaffProfile;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only staff profile, and the host for the certificates relation
 * manager.
 *
 * A view-only actor (view_staff_profile, without update) can reach this page and
 * read the profile. Whether they can also see the certificate list is a SEPARATE
 * grant — the relation manager gates itself on view_any_staff_certificate — so a
 * job title and a scanned national ID stay independently permissioned exactly as
 * the two policies intend.
 *
 * ViewRecord::authorizeAccess() aborts 403 unless StaffProfilePolicy::view()
 * passes.
 */
class ViewStaffProfile extends ViewRecord
{
    protected static string $resource = StaffProfileResource::class;

    /**
     * A plain link to the edit page rather than an EditAction, which would open a
     * modal built from this form and save it without the edit page's photo hook.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('staff.edit_staff_profile'))
                ->visible(fn (StaffProfile $record): bool => StaffProfileResource::canEdit($record))
                ->url(fn (StaffProfile $record): string => StaffProfileResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
