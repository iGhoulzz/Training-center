<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Models\Payment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Policy-authorized delivery for receipts on the private disk. */
final class ReceiptDownloadController extends Controller
{
    private const DISK = 'private';

    public function __invoke(Request $request, Payment $payment): StreamedResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->is_active) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('view', $payment);

        $path = $payment->receipt_path;

        if (
            $payment->receipt_disk !== self::DISK
            || ! is_string($path)
            || ! $this->isGeneratedReceiptPath($path)
        ) {
            abort(404);
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path, $payment->reference.'.pdf', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isGeneratedReceiptPath(string $path): bool
    {
        return preg_match('#^receipts/RCT-\d{4}-\d{6}\.pdf$#', $path) === 1;
    }
}
