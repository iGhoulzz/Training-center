<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages;

use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Exports\ReportExporter;
use App\Models\User;
use App\Support\CentreCalendar;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * The viewer's own queued spreadsheet report exports, and a link to each finished
 * one. Filament stores an `Export` row for every queued XLSX report and nothing
 * surfaced them, so a user who missed the notification had no way back.
 *
 * IT LISTS AND LINKS; IT NEVER SERVES
 * -----------------------------------
 * A ready row's "Download" is a plain link, with no handler of its own, to the
 * download route the completion notification already uses
 * (`ReportXlsxDownload`). That route re-checks the owner and the export ability on
 * every request, so a link issued yesterday is refused today if the ability has
 * since been removed. The list therefore holds no authorization of its own to keep
 * in step with the route's: what it decides is only whether to OFFER a link.
 *
 * THE PAGE OPENS FOR THE EXPORT BOUNDARY, AND ASKS ITS OWNER
 * ----------------------------------------------------------
 * `canAccess()` is `ReportExportAuthorization::allows()`, the one place that says
 * who may export a financial report (an active account holding both report
 * abilities). No permission name is restated here. Filament consults it on the
 * route, on every Livewire hydration and before registering the menu entry.
 *
 * EXACTLY FOUR STATUSES, WORKED OUT ONCE PER ROW
 * ----------------------------------------------
 *   queued       `completed_at` is null.
 *   failed       completed, but not every row was processed and succeeded.
 *   ready        the download route would serve it (a report exporter, completed,
 *                every row processed and succeeded) AND its directory still
 *                exists on its disk.
 *   unavailable  completed with every row, but not ready: the files were purged
 *                (the generated directory is deleted seven days after
 *                preparation; the row stays), or the exporter is not a report one.
 *
 * `statusOf()` is the only place a status is decided, and it remembers each
 * row's answer for the life of the request. The status column, its colour and the
 * download action's visibility all ask it, and "ready" costs a disk lookup, so
 * without the memo a ready row would touch the disk three times a render and the
 * three answers could in principle differ.
 *
 * "ready" restates the route's 404 predicate, because that predicate lives in an
 * invokable class and cannot be asked a question. The two are tied together by
 * ExportHistoryTest, which requests the real route for a row of every status and
 * for the ways to look servable and not be, and compares its answer with the
 * list's.
 *
 * WHAT IT DELIBERATELY OMITS
 * --------------------------
 * PDFs: a queued, refused or failed PDF leaves no row anywhere (the job stores the
 * file and, on success only, sends a notification), so there is nothing to list;
 * the subheading says so. No retry action: a failed export is run again from its
 * report page. No styling and no markup beyond the table, which the look task
 * (P4-T16d) owns.
 *
 * `extends Page`, NEVER `SimplePage`
 * ----------------------------------
 * The panel's `discoverPages()` call filters on `Page::class`, which is what picks
 * this class up without the panel provider being touched. See
 * `App\Filament\Pages\PasswordChange` for the full reason.
 */
final class ExportHistory extends Page implements HasTable
{
    use InteractsWithTable;

    private const STATUS_QUEUED = 'queued';

    private const STATUS_FAILED = 'failed';

    private const STATUS_READY = 'ready';

    private const STATUS_UNAVAILABLE = 'unavailable';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected string $view = 'filament.finance.export-history';

    /**
     * Each row's status, by export id, for the life of this request.
     *
     * Private, so Livewire neither sends it to the browser nor reads it back: it
     * is rebuilt on the next request, which is what a status that can change
     * (a purge, a job finishing) needs.
     *
     * @var array<int|string, self::STATUS_*>
     */
    private array $statuses = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportExportAuthorization::allows($user);
    }

    public static function getNavigationGroup(): string
    {
        return __('reports.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('export_history.navigation_label');
    }

    public function getTitle(): string
    {
        return __('export_history.title');
    }

    public function getSubheading(): string
    {
        return (string) __('export_history.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->ownExports())
            ->columns([
                TextColumn::make('report')
                    ->label(__('export_history.columns.report'))
                    ->state(fn (Export $record): string => $this->reportTitle($record)),
                TextColumn::make('created_at')
                    ->label(__('export_history.columns.requested_at'))
                    ->dateTime(timezone: CentreCalendar::TIMEZONE),
                TextColumn::make('total_rows')
                    ->label(__('export_history.columns.rows')),
                TextColumn::make('status')
                    ->label(__('export_history.columns.status'))
                    ->badge()
                    ->state(fn (Export $record): string => $this->statusOf($record))
                    ->formatStateUsing(fn (Export $record): string => $this->statusLabel($this->statusOf($record)))
                    ->color(fn (Export $record): string => $this->statusColor($this->statusOf($record))),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('export_history.actions.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Export $record): string => $this->downloadUrl($record), shouldOpenInNewTab: true)
                    ->visible(fn (Export $record): bool => $this->statusOf($record) === self::STATUS_READY),
            ])
            ->emptyStateHeading(__('export_history.empty'));
    }

    /**
     * The signed-in user's exports, newest first. The row id breaks a tie between
     * two requests in the same second, so the order does not depend on the
     * database's whim.
     *
     * Fails closed for anyone who is not a user: `canAccess()` has already refused
     * them, so this is reached only if that check is ever bypassed.
     *
     * @return Builder<Export>
     */
    private function ownExports(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Export::query()->whereRaw('1 = 0');
        }

        return Export::query()
            ->whereBelongsTo($user)
            ->latest()
            ->latest('id');
    }

    /** @return self::STATUS_* */
    private function statusOf(Export $export): string
    {
        return $this->statuses[$export->getKey()] ??= $this->resolveStatus($export);
    }

    /** @return self::STATUS_* */
    private function resolveStatus(Export $export): string
    {
        if ($export->completed_at === null) {
            return self::STATUS_QUEUED;
        }

        $everyRowSucceeded = $export->processed_rows === $export->total_rows
            && $export->successful_rows === $export->total_rows;

        if (! $everyRowSucceeded) {
            return self::STATUS_FAILED;
        }

        if (is_a($export->exporter, ReportExporter::class, true)
            && $export->getFileDisk()->exists($export->getFileDirectory())) {
            return self::STATUS_READY;
        }

        return self::STATUS_UNAVAILABLE;
    }

    /** @param self::STATUS_* $status */
    private function statusLabel(string $status): string
    {
        return (string) match ($status) {
            self::STATUS_QUEUED => __('export_history.statuses.queued'),
            self::STATUS_FAILED => __('export_history.statuses.failed'),
            self::STATUS_READY => __('export_history.statuses.ready'),
            self::STATUS_UNAVAILABLE => __('export_history.statuses.unavailable'),
        };
    }

    /** @param self::STATUS_* $status */
    private function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => 'info',
            self::STATUS_FAILED => 'danger',
            self::STATUS_READY => 'success',
            self::STATUS_UNAVAILABLE => 'gray',
        };
    }

    /**
     * The report's own page title, or a plain "other export" for a row whose
     * exporter is not one of the report exporters (or no longer exists).
     */
    private function reportTitle(Export $export): string
    {
        $exporter = $export->exporter;

        if (! is_a($exporter, ReportExporter::class, true)) {
            return (string) __('export_history.other_export');
        }

        return (string) __("reports.pages.{$exporter::kind()->value}.title");
    }

    /**
     * The same signed link the completion notification carries, valid for a day:
     * generated afresh on every render, so an old copy of the page does not hold
     * one that has gone stale in the meantime.
     */
    private function downloadUrl(Export $export): string
    {
        $routeName = Filament::getPanel('admin')->generateRouteName('pages.reports.xlsx.download');

        return URL::temporarySignedRoute($routeName, now()->addDay(), [
            'export' => $export->getKey(),
        ]);
    }
}
