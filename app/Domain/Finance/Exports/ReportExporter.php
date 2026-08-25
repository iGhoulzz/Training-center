<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\URL;
use LogicException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Adapts calculated report rows to Filament's model-backed queued exporter. */
abstract class ReportExporter extends Exporter
{
    /** @var list<string> */
    protected const COLUMNS = [];

    private ?ReportSnapshot $snapshot = null;

    abstract public static function kind(): ReportKind;

    /** @return array<ExportColumn> */
    public static function getColumns(): array
    {
        return array_map(
            fn (string $column): ExportColumn => ExportColumn::make($column)
                ->label(self::columnLabel($column))
                ->state(function (Model $record, Exporter $exporter) use ($column): string|int|null {
                    if (! $exporter instanceof self) {
                        throw new LogicException('A report column was attached to the wrong exporter.');
                    }

                    return $exporter->cell($record, $column);
                })
                ->preventFormulaInjection(),
            static::COLUMNS,
        );
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $options
     * @return Builder<Model>
     */
    public static function scopeToReport(Builder $query, array $options, User $requester): Builder
    {
        if (! ReportExportAuthorization::allows($requester)) {
            return $query->whereRaw('1 = 0');
        }

        if (static::kind() === ReportKind::WageCost) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $snapshot = self::snapshotFromOptions($options);
        $carrierIds = $snapshot->dataset->carrierIds();
        $query->whereKey($carrierIds);

        if ($carrierIds !== []) {
            $qualifiedKey = $query->getModel()->getQualifiedKeyName();
            $query->orderByRaw('FIELD('.$qualifiedKey.', '.implode(', ', $carrierIds).')');
        }

        return $query;
    }

    /** @return array<int, object> */
    public function getJobMiddleware(): array
    {
        return [new EnsureReportExportAuthorized((int) $this->export->getAttribute('user_id'))];
    }

    public function configureXlsxWriterAfterOpen(Writer $writer): Writer
    {
        $snapshot = $this->capturedSnapshot();
        $writer->addRow(Row::fromValues([self::spreadsheetSafe($snapshot->title)]));

        foreach ($snapshot->filters as $label => $value) {
            $writer->addRow(Row::fromValues([
                self::spreadsheetSafe($label),
                self::spreadsheetSafe($value),
            ]));
        }

        $writer->addRow(Row::fromValues([]));

        return $writer;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return (string) trans_choice('reports.notifications.xlsx_ready', $export->successful_rows, [
            'count' => $export->successful_rows,
        ]);
    }

    public static function modifyCompletedNotification(Notification $notification, Export $export): Notification
    {
        $requester = User::query()->find($export->getAttribute('user_id'));

        if (! ReportExportAuthorization::allows($requester)
            || $export->processed_rows !== $export->total_rows
            || $export->successful_rows !== $export->total_rows) {
            return $notification->danger()->actions([]);
        }

        $routeName = Filament::getPanel('admin')->generateRouteName('pages.reports.xlsx.download');
        $downloadUrl = URL::temporarySignedRoute($routeName, now()->addDay(), [
            'export' => $export->getKey(),
        ]);

        return $notification->actions([
            Action::make('download_xlsx')
                ->label(__('reports.actions.download_xlsx'))
                ->url($downloadUrl, shouldOpenInNewTab: true)
                ->markAsRead(),
        ]);
    }

    private function cell(Model $record, string $column): string|int|null
    {
        return $this->capturedSnapshot()->dataset->cell((int) $record->getKey(), $column);
    }

    public function capturedSnapshot(): ReportSnapshot
    {
        if ($this->snapshot instanceof ReportSnapshot) {
            return $this->snapshot;
        }

        $requester = User::query()->find($this->export->getAttribute('user_id'));

        if (! ReportExportAuthorization::allows($requester)) {
            throw new LogicException('A report export no longer has an authorized requester.');
        }

        return $this->snapshot = self::snapshotFromOptions($this->getOptions());
    }

    /** @param array<string, mixed> $options */
    private static function snapshotFromOptions(array $options): ReportSnapshot
    {
        $payload = $options['snapshot'] ?? null;

        if (! is_array($payload)) {
            throw new LogicException('A report export has no captured snapshot.');
        }

        $snapshot = ReportSnapshot::fromArray($payload);

        if ($snapshot->kind !== static::kind()) {
            throw new LogicException('A report export snapshot belongs to a different report.');
        }

        return $snapshot;
    }

    public static function spreadsheetSafe(string|int|null $value): string|int|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['-', '+'], strict: true) && is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], strict: true)
            ? "'".$value
            : $value;
    }

    private static function columnLabel(string $column): string
    {
        return (string) __(implode('.', [
            'reports',
            'columns',
            static::kind()->value,
            $column,
        ]));
    }
}
