<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\UserResource\Pages;

use App\Domain\Staff\Filament\Resources\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The explicit url() is load-bearing: without it CreateAction opens a
            // modal that persists with a bare `$model::create($data)`, skipping
            // CreateUser::afterCreate() — so no temporary password would be
            // issued and no role would ever be written. Send the administrator to
            // the real create page instead.
            CreateAction::make()
                ->label(__('staff.create_user'))
                ->url(fn (): string => UserResource::getUrl('create')),
        ];
    }
}
