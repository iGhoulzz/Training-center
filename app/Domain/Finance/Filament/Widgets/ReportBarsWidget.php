<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Widgets;

use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Filament\Pages\Reports\ReportPage;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\MonthRange;
use App\Domain\Finance\Support\ReportPeriod;
use App\Models\User;
use Filament\Widgets\Widget;
use Livewire\Attributes\Reactive;

/**
 * The chart every report shares: one horizontal bar per row, drawn as HTML and
 * SVG by `x-admin.report-bars`, under a heading and the period it covers.
 *
 * It reads the same {@see ReportDataBuilder} dataset the table beneath it
 * renders, from the applied filters the report page hands down, so a chart and
 * its table cannot disagree and a filter change redraws the chart. A subclass
 * supplies only its rows.
 *
 * @phpstan-type BarRow array{label: string, value: Money, tone: string, emphasis?: bool}
 */
abstract class ReportBarsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.finance.widgets.report-bars';

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, mixed> */
    #[Reactive]
    public array $reportFilters = [];

    public static function canView(): bool
    {
        return ReportPage::canAccess();
    }

    abstract public function getHeading(): string;

    public function getDescription(): ?string
    {
        return null;
    }

    abstract protected function kind(): ReportKind;

    /**
     * @return list<BarRow>
     */
    abstract protected function rows(ReportDataset $dataset): array;

    /** Whether values are written with an explicit sign, as the profit breakdown is. */
    protected function isSigned(): bool
    {
        return false;
    }

    /**
     * Largest absolute value first; rows of equal size keep the table's order.
     *
     * @param  list<BarRow>  $rows
     * @return list<BarRow>
     */
    protected function largestFirst(array $rows): array
    {
        // Not abs(): it returns a float for PHP_INT_MIN, and no float touches money here.
        $magnitude = static fn (Money $value): int => $value->isNegative() ? -$value->dirham : $value->dirham;

        usort($rows, static fn (array $a, array $b): int => $magnitude($b['value']) <=> $magnitude($a['value']));

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $dataset = app(ReportDataBuilder::class)->build($this->kind(), $this->reportFilters, $user);

        return [
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'period' => $this->periodLabel(),
            'rows' => $this->rows($dataset),
            'signed' => $this->isSigned(),
        ];
    }

    /**
     * The dates the dataset covers, written as "1 Oct 2026 – 31 Oct 2026".
     *
     * Called only after the dataset is built, so the filters are already
     * validated by the same ReportPeriod or MonthRange parsing the data used.
     * A monthly report reads from the first day of its first month to the last
     * day of its last month.
     */
    private function periodLabel(): string
    {
        $from = (string) $this->reportFilters['from'];
        $to = (string) $this->reportFilters['to'];

        if (in_array($this->kind(), [ReportKind::WageCost, ReportKind::Profit], true)) {
            [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
            [$toYear, $toMonth] = array_map('intval', explode('-', $to));
            $months = MonthRange::between($fromYear, $fromMonth, $toYear, $toMonth);
            $from = $months->firstLocalDate();
            $to = $months->lastLocalDate();
        }

        $format = (string) __('reports.charts.date_format');

        return (string) __('reports.charts.period', [
            'from' => ReportPeriod::parseLocalDate($from)->translatedFormat($format),
            'to' => ReportPeriod::parseLocalDate($to)->translatedFormat($format),
        ]);
    }
}
