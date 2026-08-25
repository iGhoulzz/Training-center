<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use LogicException;
use SplTempFileObject;

/** Applies the report exporter's run-time authorization before chunk dispatch. */
final class PrepareReportCsvExport extends PrepareCsvExport
{
    /** @return array<int, object> */
    public function middleware(): array
    {
        return $this->exporter->getJobMiddleware();
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if (! $this->exporter instanceof ReportExporter) {
            throw new LogicException('The report preparation job received a non-report exporter.');
        }

        $snapshot = $this->exporter->capturedSnapshot();
        $disk = $this->export->getFileDisk();
        $directory = $this->export->getFileDirectory();
        $delimiter = $this->exporter::getCsvDelimiter();

        $headers = Writer::from(new SplTempFileObject);
        $headers->setDelimiter($delimiter);
        $headers->insertOne(array_values($this->columnMap));
        $disk->put(
            $directory.DIRECTORY_SEPARATOR.'headers.csv',
            $headers->toString(),
            Filesystem::VISIBILITY_PRIVATE,
        );

        $rows = Writer::from(new SplTempFileObject);
        $rows->setDelimiter($delimiter);

        foreach ($snapshot->dataset->rows as $row) {
            $rows->insertOne(array_map(
                function (string $column) use ($row): string|int|null {
                    if (! array_key_exists($column, $row['cells'])) {
                        throw new LogicException("A frozen report row has no [{$column}] cell.");
                    }

                    return ReportExporter::spreadsheetSafe($row['cells'][$column]);
                },
                array_keys($this->columnMap),
            ));
        }

        $disk->put(
            $directory.DIRECTORY_SEPARATOR.str_pad('1', 16, '0', STR_PAD_LEFT).'.csv',
            $rows->toString(),
            Filesystem::VISIBILITY_PRIVATE,
        );

        $rowCount = count($snapshot->dataset->rows);

        DB::transaction(function () use ($rowCount): void {
            $this->export::query()
                ->whereKey($this->export->getKey())
                ->lockForUpdate()
                ->update([
                    'total_rows' => $rowCount,
                    'processed_rows' => $rowCount,
                    'successful_rows' => $rowCount,
                ]);
        });
    }
}
