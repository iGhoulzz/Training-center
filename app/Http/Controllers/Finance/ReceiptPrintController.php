<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ReceiptViewData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payment receipt as an 80 mm thermal-printer page (P4-T24).
 *
 * RENDERED ON DEMAND, NEVER STORED. Unlike `ReceiptDownloadController`, which
 * streams a PDF from the private disk, this reads the payment's immutable
 * receipt snapshot and its tenders and renders HTML each time it is opened. No
 * file, no column and no job stand behind it, so it works the moment a payment
 * is recorded, before the PDF exists. A payment with no snapshot answers 404.
 *
 * THE SAME GATE AS THE DOWNLOAD, IN THE SAME ORDER: an active user, then
 * `PaymentPolicy::view()`, both before anything about the payment is revealed.
 * An unauthorized caller therefore cannot tell a payment without a snapshot from
 * one with. The route sits behind `AuthenticatePrivateFileSession` and a
 * throttle, in a group of its own; see routes/web.php.
 *
 * THE PAGE IS PRIVATE. It names a student and a sum of money, so it is marked
 * `no-store` and `nosniff` as the download is, and `noindex` besides: it is a
 * page, which a crawler or a shared proxy could otherwise keep.
 */
final class ReceiptPrintController extends Controller
{
    public function __invoke(Request $request, Payment $payment, ReceiptViewData $viewData): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->is_active) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('view', $payment);

        try {
            $html = $viewData->render('finance.receipt-print', $payment);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
