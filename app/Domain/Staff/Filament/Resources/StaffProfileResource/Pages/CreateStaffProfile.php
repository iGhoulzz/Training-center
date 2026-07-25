<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages;

use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creating a staff profile.
 *
 * The profile's own attributes are ordinary data written by Filament. The photo
 * field is not offered here — UpdateStaffPhotoAction authorizes `update`, so a
 * photo at creation would require update_staff_profile to finish a create. Add
 * the photo from the edit page after the record exists.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes StaffProfilePolicy::create().
 */
class CreateStaffProfile extends CreateRecord
{
    protected static string $resource = StaffProfileResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;
}
