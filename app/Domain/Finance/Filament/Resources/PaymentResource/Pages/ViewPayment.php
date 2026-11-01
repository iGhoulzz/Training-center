<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PaymentResource\Pages;

use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Models\Payment;
use App\Filament\Details\AdminDetails;
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
 * through the same shared balance definitions — there is no
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
        return AdminDetails::schema($schema, [
            AdminDetails::section(__('payments.receipt_details'), [
                AdminDetails::code('reference', __('payments.reference')),

                AdminDetails::text('student.full_name', __('payments.student')),

                AdminDetails::dateTime('received_at', __('payments.received_at')),

                AdminDetails::money('tenders_sum_amount', __('payments.total'), 'payments.amount_lyd'),

                // See the class docblock: exactly one allocation in practice,
                // so the first is the whole answer — same reasoning as the
                // table's `bill` column.
                AdminDetails::text('bill', __('payments.bill'))
                    ->state(fn (Payment $record): ?string => $record->allocations->first()?->charge?->reference),

                AdminDetails::person('recordedBy.name', __('payments.recorded_by')),

                /*
                 * Nothing is erased by a reversal (design section 5) — these
                 * three columns are additional facts about the payment, not a
                 * replacement for anything above. Hidden entirely rather than
                 * shown blank when the payment was never reversed, matching
                 * ChargeResource::ViewCharge's written_off_at / written_off_by /
                 * written_off_reason group.
                 */
                AdminDetails::dateTime('reversed_at', __('payments.reversed_on'))
                    ->visible(fn (Payment $record): bool => $record->isReversed()),

                AdminDetails::person('reversedBy.name', __('payments.reversed_by'))
                    ->visible(fn (Payment $record): bool => $record->isReversed()),

                AdminDetails::longText('reversal_reason', __('payments.reason'))
                    ->visible(fn (Payment $record): bool => $record->isReversed()),
            ], wide: true),
        ]);
    }
}
