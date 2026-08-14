<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages;

use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListStaffCompensations extends ListRecords
{
    protected static string $resource = StaffCompensationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('payroll.change_compensation'))
                ->visible(fn (): bool => StaffCompensationResource::canCreate())
                ->url(fn (): string => StaffCompensationResource::getUrl('create')),
        ];
    }
}
