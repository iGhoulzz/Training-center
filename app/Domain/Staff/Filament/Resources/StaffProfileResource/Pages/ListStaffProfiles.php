<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages;

use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListStaffProfiles extends ListRecords
{
    protected static string $resource = StaffProfileResource::class;

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction — the same
     * reasoning as ListUsers and ListStudents: CreateAction keeps a mountable
     * server-side create handler even when ->url() is set.
     *
     * canCreate() is checked explicitly because a plain Action carries no
     * authorization of its own.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('staff.create_staff_profile'))
                ->visible(fn (): bool => StaffProfileResource::canCreate())
                ->url(fn (): string => StaffProfileResource::getUrl('create')),
        ];
    }
}
