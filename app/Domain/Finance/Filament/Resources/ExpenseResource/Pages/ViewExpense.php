<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseResource\Pages;

use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Models\Expense;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One expense in full, read-only aside from the one record action.
 *
 * THE SAME ACTION AS THE TABLE ROW, NOT A SEPARATE COPY
 * -----------------------------------------------------
 * `getHeaderActions()` calls `ExpenseResource::reverseAction()` — the shared builder
 * `ExpenseResource::table()` uses — so the list and this page cannot drift apart on
 * what "reverse" does. There is no edit action and no delete action here: an
 * expense is never edited and never deleted.
 */
final class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExpenseResource::reverseAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('expenses.payment_details'))->columns(2)->schema([
                TextEntry::make('category.name_en')
                    ->label(__('expenses.category'))
                    ->state(fn (Expense $record): string => $record->category->name()),

                TextEntry::make('amount')
                    ->label(__('expenses.amount'))
                    ->formatStateUsing(fn (string $state): string => ExpenseResource::formatMoney($state)),

                TextEntry::make('paid_on')
                    ->label(__('expenses.paid_on'))
                    ->date(),

                TextEntry::make('description')
                    ->label(__('expenses.description')),

                TextEntry::make('recordedBy.name')
                    ->label(__('expenses.recorded_by')),

                /*
             * Nothing is erased by a reversal — these three columns are
             * additional facts about the expense, not a replacement for anything
             * above. Hidden entirely rather than shown blank when the expense was
             * never reversed.
             */
                TextEntry::make('reversed_at')
                    ->label(__('expenses.reversed_on'))
                    ->dateTime()
                    ->visible(fn (Expense $record): bool => $record->isReversed()),

                TextEntry::make('reversedBy.name')
                    ->label(__('expenses.reversed_by'))
                    ->visible(fn (Expense $record): bool => $record->isReversed()),

                TextEntry::make('reversal_reason')
                    ->label(__('expenses.reason'))
                    ->visible(fn (Expense $record): bool => $record->isReversed()),
            ]),
        ]);
    }
}
