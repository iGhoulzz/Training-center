<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction — the same
     * reasoning as ListUsers: CreateAction keeps a mountable server-side create
     * handler even when ->url() is set, and that handler persists with a bare
     * $model::create($data) that never runs the create page's hooks.
     *
     * canCreate() is checked explicitly because a plain Action carries no
     * authorization of its own; without it the button renders for view-only
     * staff, who then hit a 403 on the page behind it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('enrollment.create_student'))
                ->visible(fn (): bool => StudentResource::canCreate())
                ->url(fn (): string => StudentResource::getUrl('create')),
        ];
    }
}
