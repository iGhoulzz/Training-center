<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseResource\Pages;

use App\Domain\Finance\Filament\Resources\ExpenseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link to the create page, not a CreateAction: the create page owns
            // the write, through RecordExpenseAction.
            Action::make('create')
                ->label(__('expenses.record_expense'))
                ->visible(fn (): bool => ExpenseResource::canCreate())
                ->url(fn (): string => ExpenseResource::getUrl('create')),
        ];
    }
}
