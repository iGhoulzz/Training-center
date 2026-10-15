<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ChargeResource\Pages;

use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Reports\OutstandingAgedReport;
use App\Domain\Finance\Support\Money;
use App\Support\CentreCalendar;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

/**
 * The bills, listed. Read-plus-two-actions — see `ChargeResource`'s own
 * docblock.
 *
 * NO HEADER ACTIONS, AND NO CREATE PAGE TO LINK TO.
 * --------------------------------------------------
 * `ChargePolicy::create()` refuses unconditionally (design section 4) and
 * `ChargeResource::canCreate()` says so explicitly. `getHeaderActions()` is not
 * overridden for the same reason `ActivityResource`'s `ListActivities` leaves
 * it alone: `ListRecords` returns an empty array by default, and writing `[]`
 * here would read as "someone considered adding one".
 *
 * @property-read array<string, array{amount: Money, count: int}> $ageingTotals
 */
class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    /** @return array<string, array{amount: Money, count: int}> */
    #[Computed]
    public function ageingTotals(): array
    {
        return app(OutstandingAgedReport::class)->totalsAsOf(CentreCalendar::localise(now())->toDateString());
    }

    public function getSubheading(): string
    {
        return __('charges.outstanding_bills', ['count' => array_sum(array_column($this->ageingTotals, 'count'))]);
    }

    public function content(Schema $schema): Schema
    {
        $content = parent::content($schema)->getComponents();
        $totals = $this->ageingTotals;
        $maximum = max(1, array_reduce($totals, fn (Money $sum, array $row): Money => $sum->add($row['amount']), Money::zero())->dirham);
        $cards = [];
        foreach ($totals as $bucket => $total) {
            $label = match ($bucket) {
                OutstandingAgedReport::BUCKET_0_30 => __('charges.age_current'),
                OutstandingAgedReport::BUCKET_31_60 => __('charges.age_31_60'),
                OutstandingAgedReport::BUCKET_61_90 => __('charges.age_61_90'),
                default => __('charges.age_91_plus'),
            };
            $tone = match ($bucket) {
                OutstandingAgedReport::BUCKET_0_30 => 'success',
                OutstandingAgedReport::BUCKET_31_60 => 'warning',
                OutstandingAgedReport::BUCKET_61_90 => 'primary',
                default => 'danger',
            };
            $colour = match ($bucket) {
                OutstandingAgedReport::BUCKET_0_30 => 'text-success-400! [&_svg]:text-success-400!',
                OutstandingAgedReport::BUCKET_31_60 => 'text-warning-400! [&_svg]:text-warning-400!',
                OutstandingAgedReport::BUCKET_61_90 => 'text-ageing-orange! [&_svg]:text-ageing-orange!',
                default => 'text-danger-400! [&_svg]:text-danger-400!',
            };
            $cards[] = Section::make()->schema([
                TextEntry::make('age_'.$bucket)->label($label)
                    ->state($total['amount']->toDecimal())->formatStateUsing(fn (string $state): string => ChargeResource::formatMoney($state))
                    ->extraEntryWrapperAttributes(['class' => '[&_.fi-in-entry-label]:text-[12px]! [&_.fi-in-entry-label]:font-normal! [&_.fi-in-entry-label]:text-gray-400!'])
                    ->extraAttributes(['class' => 'text-[20px]! font-semibold tracking-[-0.02em] leading-normal '.$colour]),
                TextEntry::make('age_bar_'.$bucket)->hiddenLabel()->state(new HtmlString(Blade::render(
                    '<x-admin.meter :value="$value" :maximum="$maximum" :label="$label" :tone="$tone" />',
                    ['value' => $total['amount']->dirham, 'maximum' => $maximum,
                        'label' => __('charges.bill_count', ['count' => $total['count']]), 'tone' => $tone],
                )))->extraAttributes(['class' => '[&_svg]:h-1! '.$colour]),
            ])->extraAttributes(['class' => 'rounded-xl! [&_.fi-section-content]:p-3.75! [&_.fi-sc]:gap-2.25!']);
        }

        return $schema->components([Grid::make(['default' => 1, 'sm' => 2, 'xl' => 4])
            ->extraAttributes(['class' => '[&>.fi-sc]:gap-3.5!'])->schema($cards), ...$content]);
    }
}
