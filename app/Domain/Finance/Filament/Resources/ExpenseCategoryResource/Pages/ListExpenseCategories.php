<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages;

use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link to the create page, not a CreateAction: the create page owns
            // the write, through CreateExpenseCategoryAction.
            Action::make('create')
                ->label(__('expenses.create_category'))
                ->visible(fn (): bool => ExpenseCategoryResource::canCreate())
                ->url(fn (): string => ExpenseCategoryResource::getUrl('create')),
        ];
    }
}
