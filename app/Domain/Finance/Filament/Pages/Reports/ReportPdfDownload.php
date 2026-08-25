<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves a generated report only to the authorized user who requested it. */
final class ReportPdfDownload
{
    public function __invoke(Request $request, int $requester, string $reference): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->getKey() === $requester
            && ReportExportAuthorization::allows($user),
            403,
        );
        abort_unless(Str::isUuid($reference), 404);

        $path = "financial-reports/{$requester}/{$reference}.pdf";
        abort_unless(Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->download($path, "financial-report-{$reference}.pdf");
    }
}
