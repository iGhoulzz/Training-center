<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PaymentResource\Pages;

use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Models\Payment;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * One payment in full, read-only aside from the one record action.
 *
 * THE SAME ACTION AS THE TABLE ROW, NOT A SEPARATE COPY
 * ---------------------------------------------------------
 * `getHeaderActions()` calls `PaymentResource::reverseAction()` — the same
 * shared builder `PaymentResource::table()` uses — so the list and this
 * page cannot drift apart on what "reverse" does, exactly the reasoning
 * `ChargeResource`'s `ViewCharge` already establishes for its two actions.
 *
 * `tenders_sum_amount` AND THE EAGER-LOADED RELATIONS NEED NO SECOND QUERY
 * HERE, UNLIKE `ViewCharge`'s `ChargeBalance` CALLS
 * ----------------------------------------------------------------------------
 * `ViewRecord::resolveRecord()` resolves this page's `$record` through
 * `PaymentResource::getEloquentQuery()` itself (Filament's
 * `HasRoutes::getRecordRouteBindingEloquentQuery()` returns
 * `static::getEloquentQuery()` with no override here), so the `withSum()`
 * alias and the `student` / `recordedBy` / `allocations.charge` eager loads
 * `PaymentResource::getEloquentQuery()` already selects are present on
 * `$record` by the time this infolist renders. `ChargeResource::ViewCharge`
 * calls `ChargeBalance::allocatedFor()` / `outstandingFor()` instead
 * because `allocated_amount` is never selected by
 * `ChargeResource::getEloquentQuery()` in the first place — there is no
 * equivalent gap here to work around, and no "PaymentBalance"-shaped
 * utility class exists to call even if there were one.
 */
class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentResource::reverseAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('reference')
                ->label(__('payments.reference')),

            TextEntry::make('student.full_name')
                ->label(__('payments.student')),

            TextEntry::make('received_at')
                ->label(__('payments.received_at'))
                ->dateTime(),

            TextEntry::make('tenders_sum_amount')
                ->label(__('payments.total'))
                ->formatStateUsing(fn (string $state): string => PaymentResource::formatMoney($state)),

            // See the class docblock: exactly one allocation in practice,
            // so the first is the whole answer — same reasoning as the
            // table's `bill` column.
            TextEntry::make('bill')
                ->label(__('payments.bill'))
                ->state(fn (Payment $record): ?string => $record->allocations->first()?->charge?->reference),

            TextEntry::make('recordedBy.name')
                ->label(__('payments.recorded_by')),

            /*
             * Nothing is erased by a reversal (design section 5) — these
             * three columns are additional facts about the payment, not a
             * replacement for anything above. Hidden entirely rather than
             * shown blank when the payment was never reversed, matching
             * ChargeResource::ViewCharge's written_off_at / written_off_by /
             * written_off_reason group.
             */
            TextEntry::make('reversed_at')
                ->label(__('payments.reversed_on'))
                ->dateTime()
                ->visible(fn (Payment $record): bool => $record->isReversed()),

            TextEntry::make('reversedBy.name')
                ->label(__('payments.reversed_by'))
                ->visible(fn (Payment $record): bool => $record->isReversed()),

            TextEntry::make('reversal_reason')
                ->label(__('payments.reason'))
                ->visible(fn (Payment $record): bool => $record->isReversed()),
        ]);
    }
}
