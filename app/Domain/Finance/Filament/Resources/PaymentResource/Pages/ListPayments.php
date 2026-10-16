<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PaymentResource\Pages;

use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Payments, listed. Read-plus-one-action — see `PaymentResource`'s own
 * docblock.
 *
 * NO CREATE PAGE TO LINK TO.
 * Unlike `ChargePolicy::create()`, `PaymentPolicy::create()` is a real
 * permission `admin` and `super_admin` both hold — but `getPages()` on
 * `PaymentResource` registers no create route for it to gate, because
 * design section 2 requires a payment to be recorded through the
 * collection flow (task 9), against a bill already open in front of the
 * operator, not by typing a row into this resource. The header links to the
 * existing daily report, whose page owns its queued exports. It does not
 * promise a payment-register export that the application cannot produce.
 */
class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tender_report')->label(__('payments.tender_report'))
                ->icon(Heroicon::OutlinedChartBar)
                ->visible(fn (): bool => DailyTenderReportPage::canAccess())
                ->url(fn (): string => DailyTenderReportPage::getUrl()),
        ];
    }

    public function getSubheading(): string
    {
        return __('payments.register_hint');
    }
}
