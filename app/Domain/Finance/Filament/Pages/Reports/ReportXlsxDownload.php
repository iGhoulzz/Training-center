<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Exports\ReportExporter;
use App\Models\User;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a complete report export only while its owner remains authorized.
 *
 * THE RETURN TYPE FOLLOWS FILAMENT'S CONTRACT, NOT THE CLASS BEHIND IT.
 * This was `StreamedResponse` until Filament 5.8, where `ExportFormat::getDownloader()`
 * began returning the new `Downloader` interface, whose `__invoke()` promises
 * only a `Response`. The concrete `XlsxDownloader` still returns a
 * `StreamedResponse`, so nothing about the response actually changed — but the
 * narrower declaration was this application asserting a guarantee somebody
 * else's interface no longer makes, and neither a test nor a caller ever
 * depended on it.
 *
 * Keeping the narrow type would mean bypassing `ExportFormat`, whose mapping
 * exists to keep a format and its downloader in step. If streaming ever has to
 * be guaranteed here rather than merely observed, what that wants is a test
 * which fails when the response buffers — not a type nothing checks.
 */
final class ReportXlsxDownload
{
    public function __invoke(Request $request, Export $export): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $export->user()->is($user)
            && ReportExportAuthorization::allows($user),
            403,
        );
        abort_unless(
            is_a($export->exporter, ReportExporter::class, true)
            && $export->completed_at !== null
            && $export->processed_rows === $export->total_rows
            && $export->successful_rows === $export->total_rows,
            404,
        );

        return ExportFormat::Xlsx->getDownloader()($export);
    }
}
