<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
use App\Support\CentreCalendar;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The one place a payment receipt's view variables are built (P4-T24).
 *
 * A receipt exists in two layouts: the A4 PDF `GenerateReceiptJob` stores, and
 * the 80 mm page `ReceiptPrintController` renders on demand for a thermal
 * printer. Both are views of the same immutable snapshot and the same tenders.
 * They are built here, once, so the paper and the PDF cannot disagree: a field
 * added, reformatted or re-ordered in one place changes both.
 *
 * THE LOCALE IS THE SNAPSHOT'S, NOT THE CALLER'S. A queue worker has no user and
 * a front-desk request has the desk's locale, but the receipt must read in the
 * language the person at the counter was handed when the money was recorded.
 * `render()` therefore sets the snapshot's locale around the render and restores
 * the caller's in a `finally`, so a failing view cannot leave a long-lived
 * worker speaking Arabic to the next job. Tender labels come from a translation
 * lookup, which is why the variables are built INSIDE that window and are not
 * exposed on their own: built outside it they would be in the wrong language.
 *
 * Money is carried as the snapshot's own `decimal:3` strings and is never turned
 * into a number here. The discount is carried as the stored two-decimal string,
 * or '0' when the bill had none; the views wrap it in `receipt.discount_percent`
 * so it reads as a percentage beside the three-decimal LYD amounts.
 *
 * The snapshot is reached through `Payment::receiptSnapshot()` and is not named
 * in this file: receipt snapshots are document input and are kept out of every
 * live calculation, which ActionBoundaryArchTest enforces by name.
 */
final class ReceiptViewData
{
    /**
     * Render one of the receipt views for a payment, in the snapshot's locale.
     *
     * @param  string  $view  `finance.receipt` (A4 PDF) or `finance.receipt-print` (80 mm page)
     *
     * @throws ModelNotFoundException When the payment has no snapshot.
     */
    public function render(string $view, Payment $payment): string
    {
        $snapshot = $payment->receiptSnapshot()->firstOrFail();
        $tenderRows = $payment->tenders()->orderBy('id')->get();

        $originalLocale = app()->getLocale();

        try {
            app()->setLocale($snapshot->locale);

            return view($view, [
                'snapshot' => $snapshot,
                'tenders' => $tenderRows->map(fn (PaymentTender $tender): array => [
                    'label' => $tender->method->label(),
                    'amount' => (string) $tender->amount,
                ])->all(),
                'paymentDate' => CentreCalendar::localise($snapshot->received_at)->format('Y-m-d H:i'),
                'locale' => $snapshot->locale,
                'direction' => str_starts_with($snapshot->locale, 'ar') ? 'rtl' : 'ltr',
                'discountPercent' => $snapshot->discount_percentage ?? '0',
            ])->render();
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
