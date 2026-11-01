<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseResource\Pages;

use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Models\Expense;
use App\Filament\Details\AdminDetails;
use Filament\Resources\Pages\ViewRecord;
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
        return AdminDetails::schema($schema, [
            AdminDetails::section(__('expenses.payment_details'), [
                AdminDetails::text('category.name_en', __('expenses.category'))
                    ->state(fn (Expense $record): string => $record->category->name()),

                AdminDetails::money('amount', __('expenses.amount'), 'expenses.amount_lyd'),

                AdminDetails::date('paid_on', __('expenses.paid_on')),

                AdminDetails::longText('description', __('expenses.description')),

                AdminDetails::person('recordedBy.name', __('expenses.recorded_by')),

                /*
                 * Nothing is erased by a reversal — these three columns are
                 * additional facts about the expense, not a replacement for anything
                 * above. Hidden entirely rather than shown blank when the expense was
                 * never reversed.
                 */
                AdminDetails::dateTime('reversed_at', __('expenses.reversed_on'))
                    ->visible(fn (Expense $record): bool => $record->isReversed()),

                AdminDetails::person('reversedBy.name', __('expenses.reversed_by'))
                    ->visible(fn (Expense $record): bool => $record->isReversed()),

                AdminDetails::longText('reversal_reason', __('expenses.reason'))
                    ->visible(fn (Expense $record): bool => $record->isReversed()),
            ], wide: true),
        ]);
    }
}
