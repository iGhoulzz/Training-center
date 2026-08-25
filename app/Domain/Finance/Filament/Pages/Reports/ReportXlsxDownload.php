<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Exports\ReportExporter;
use App\Models\User;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves a complete report export only while its owner remains authorized. */
final class ReportXlsxDownload
{
    public function __invoke(Request $request, Export $export): StreamedResponse
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
