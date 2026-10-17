<x-filament-panels::page>
    <nav aria-label="{{ __('reports.navigation_group') }}" class="flex min-w-0 flex-wrap gap-2">
        @foreach ($this->reportNavigation() as $report)
            <a href="{{ $report->url }}" @if ($report->active) aria-current="page" @endif
                @class([
                    'inline-flex min-h-11 items-center rounded-full border px-[13px] py-[7px] text-[12.5px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-400 lg:min-h-0',
                    'border-primary-500/40 bg-primary-500/10 text-primary-400' => $report->active,
                    'border-hairline bg-fill-subtle text-gray-400 hover:border-primary-500/40 hover:bg-primary-500/10 hover:text-primary-400' => ! $report->active,
                ])>{{ $report->label }}</a>
        @endforeach
    </nav>

    <form wire:submit="applyFilters" class="flex min-w-0 flex-col gap-4 rounded-[14px] bg-gray-900 p-[18px] ring-1 ring-hairline">
        {{ $this->form }}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs leading-relaxed text-gray-400">{{ __('reports.presentation.queued_hint') }}</p>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('reports.actions.apply_filters') }}</x-filament::button>
        </div>
    </form>

    @if ($periodTotal !== null)
        <div class="grid min-w-0 grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(260px,340px)]">
            <div class="min-w-0">{{ $this->reportCharts }}</div>
            <aside data-report-summary class="flex min-w-0 flex-col gap-[13px] rounded-[14px] bg-gray-900 p-[18px] ring-1 ring-hairline">
                <h2 class="text-[13px] font-semibold text-gray-50">{{ __('reports.presentation.period_total') }}</h2>
                <p class="flex flex-wrap items-baseline gap-2 tabular-nums">
                    <span class="text-[30px] font-semibold tracking-[-0.03em] text-gray-50">{{ $periodTotal }}</span>
                    <span class="text-[13px] text-gray-400">{{ __('reports.presentation.currency') }}</span>
                </p>
                <p class="text-xs leading-relaxed text-gray-400">{{ $this->summaryDescription() }}</p>
            </aside>
        </div>
    @endif

    <div class="min-w-0 overflow-x-auto rounded-[14px] bg-gray-900 ring-1 ring-hairline" role="region" aria-label="{{ __('reports.presentation.rows') }}" tabindex="0">
        <table class="w-full divide-y divide-hairline text-[13.5px]">
            <caption class="sr-only">{{ $this->getTitle() }}</caption>
            <thead class="bg-fill-subtle">
                <tr>
                    @foreach ($dataset->columns as $label)
                        <th scope="col" class="whitespace-nowrap px-4 py-3 text-start text-[13px] font-semibold text-gray-300">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($dataset->rows as $row)
                    <tr class="transition-colors hover:bg-fill-subtle">
                        @foreach (array_keys($dataset->columns) as $column)
                            <td class="whitespace-pre-line px-4 py-3 text-gray-200 tabular-nums">{{ $row['cells'][$column] }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(count($dataset->columns), 1) }}" class="px-4 py-8 text-center text-gray-400">{{ __('reports.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
