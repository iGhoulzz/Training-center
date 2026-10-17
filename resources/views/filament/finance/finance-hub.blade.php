<x-filament-panels::page>
    <div @class(['grid min-w-0 grid-cols-1 items-start gap-4', 'xl:grid-cols-[minmax(0,1fr)_minmax(260px,380px)]' => $exportHistoryUrl !== null])>
        <div class="flex min-w-0 flex-col gap-[14px]">
            @foreach ($groups as $group)
                <section data-finance-hub-group="{{ $group->id }}" aria-labelledby="finance-group-{{ $group->id }}" class="rounded-[14px] bg-gray-900 p-[17px] ring-1 ring-hairline">
                    <h2 id="finance-group-{{ $group->id }}" class="text-[11.5px] font-semibold tracking-[0.06em] text-gray-400 uppercase">{{ $group->heading }}</h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-400">{{ $group->description }}</p>
                    <ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[repeat(auto-fit,minmax(168px,1fr))]">
                        @foreach ($group->tiles as $tile)
                            <li class="relative min-w-0 rounded-[11px] border border-hairline bg-fill-subtle px-3 py-[11px] transition-colors hover:border-primary-500/40 hover:bg-primary-500/10 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary-400">
                                <a href="{{ $tile->url }}" data-finance-hub-tile="{{ $tile->id }}" class="flex items-center gap-2.5 text-start after:absolute after:inset-0 after:rounded-[11px] focus-visible:outline-none">
                                    <span class="min-w-0 flex-1 text-[13px] font-semibold text-gray-50">{{ $tile->label }}</span>
                                    <x-filament::icon icon="heroicon-o-chevron-right" class="size-3.5 shrink-0 text-gray-400 rtl:rotate-180" />
                                </a>
                                <p class="mt-1 pe-6 text-[11px] leading-relaxed text-gray-400">{{ $tile->description }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
        @if ($exportHistoryUrl !== null)
            <aside class="min-w-0 overflow-hidden rounded-[14px] bg-gray-900 ring-1 ring-hairline">
                <div class="flex flex-col gap-1 border-b border-hairline-faint px-[18px] py-4">
                    <h2 class="text-sm font-semibold text-gray-50">{{ __('export_history.title') }}</h2>
                    <p class="text-[11.5px] leading-relaxed text-gray-400">{{ __('reports.presentation.queued_hint') }}</p>
                </div>
                <div class="flex flex-col gap-4 px-[18px] py-4">
                    <p class="text-xs leading-relaxed text-gray-400">{{ __('export_history.subheading') }}</p>
                    <x-filament::button :href="$exportHistoryUrl" tag="a" color="gray" outlined icon="heroicon-o-arrow-down-tray">{{ __('reports.presentation.view_exports') }}</x-filament::button>
                </div>
            </aside>
        @endif
    </div>
</x-filament-panels::page>
