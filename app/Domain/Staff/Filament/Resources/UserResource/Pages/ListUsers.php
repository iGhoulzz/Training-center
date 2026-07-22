<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\UserResource\Pages;

use App\Domain\Staff\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction.
     *
     * CreateAction carries a server-side create handler that stays mountable
     * even when ->url() is set: url() only replaces the browser click
     * behaviour, so a crafted Livewire mount could still reach the handler,
     * which persists with a bare `$model::create($data)` and never runs
     * CreateUser::afterCreate(). That path would skip the temporary password
     * and the role write entirely.
     *
     * A plain Action has no create handler to reach — there is nothing to
     * disable, because nothing was ever registered. Creation happens only on
     * the full create page, whose hooks route through the Actions.
     *
     * canCreate() is checked explicitly: this Action has no resource-aware
     * authorization of its own, so without it the button would render for an
     * actor who is not permitted to create.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('staff.create_user'))
                ->visible(fn (): bool => UserResource::canCreate())
                ->url(fn (): string => UserResource::getUrl('create')),
        ];
    }
}
