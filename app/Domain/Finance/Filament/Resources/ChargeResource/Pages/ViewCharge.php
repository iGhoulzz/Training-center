<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ChargeResource\Pages;

use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * One bill in full, read-only aside from the two record actions.
 *
 * THE SAME TWO ACTIONS AS THE TABLE ROW, NOT A SEPARATE COPY
 * ------------------------------------------------------------
 * `getHeaderActions()` calls `ChargeResource::adjustAction()` and
 * `ChargeResource::writeOffAction()` — the same shared builders
 * `ChargeResource::table()` uses — so the list and this page cannot drift apart
 * on what "adjust" or "write off" does, the same reasoning
 * `BatchResource::deleteAction()` already establishes for this codebase.
 *
 * OUTSTANDING AND ALLOCATED ARE READ THROUGH ChargeBalance's PHP ENTRY POINT
 * HERE, NOT ITS SQL ONE
 * ---------------------------------------------------------------------------
 * This page renders exactly one charge, not a sortable table of them, so there
 * is no query to compose the SQL fragment into.
 * `ChargeBalance::outstandingFor()` / `allocatedFor()` run the identical SQL
 * string under the hood (see that class's own docblock) — the two answers
 * cannot disagree with what `ChargeResource::table()` shows for the same row.
 */
class ViewCharge extends ViewRecord
{
    protected static string $resource = ChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChargeResource::adjustAction(),
            ChargeResource::writeOffAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('reference')
                ->label(__('charges.reference')),

            TextEntry::make('enrollment.reference')
                ->label(__('charges.enrollment_reference')),

            TextEntry::make('enrollment.batch.course.code')
                ->label(__('charges.course')),

            TextEntry::make('enrollment.batch.code')
                ->label(__('charges.batch')),

            TextEntry::make('list_price')
                ->label(__('charges.list_price'))
                ->formatStateUsing(fn (string $state): string => ChargeResource::formatMoney($state)),

            TextEntry::make('discount_percentage')
                ->label(__('charges.discount'))
                ->formatStateUsing(fn (?string $state): string => $state === null
                    ? __('charges.no_discount')
                    : __('charges.discount_percentage_value', ['percentage' => $state])),

            // The frozen figure — see Charge's own docblock on why this can
            // legitimately disagree with list_price and discount_percentage
            // after an AdjustChargeAction correction.
            TextEntry::make('amount')
                ->label(__('charges.amount'))
                ->formatStateUsing(fn (string $state): string => ChargeResource::formatMoney($state)),

            TextEntry::make('allocated')
                ->label(__('charges.allocated'))
                ->state(fn (Charge $record): string => ChargeBalance::allocatedFor((int) $record->getKey())->toDecimal())
                ->formatStateUsing(fn (string $state): string => ChargeResource::formatMoney($state)),

            TextEntry::make('outstanding')
                ->label(__('charges.outstanding'))
                ->state(fn (Charge $record): string => ChargeBalance::outstandingFor((int) $record->getKey())->toDecimal())
                ->formatStateUsing(fn (string $state): string => ChargeResource::formatMoney($state)),

            TextEntry::make('due_date')
                ->label(__('charges.due_date'))
                ->date(),

            /*
             * Nothing is erased by a write-off (design section 4) — these three
             * columns are additional facts about the bill, not a replacement for
             * anything above. Hidden entirely rather than shown blank when the
             * charge was never written off, so the detail page does not read as
             * though something is missing.
             */
            TextEntry::make('written_off_at')
                ->label(__('charges.written_off_at'))
                ->dateTime()
                ->visible(fn (Charge $record): bool => $record->isWrittenOff()),

            TextEntry::make('writtenOffBy.name')
                ->label(__('charges.written_off_by'))
                ->visible(fn (Charge $record): bool => $record->isWrittenOff()),

            TextEntry::make('written_off_reason')
                ->label(__('charges.written_off_reason'))
                ->visible(fn (Charge $record): bool => $record->isWrittenOff()),
        ]);
    }
}
