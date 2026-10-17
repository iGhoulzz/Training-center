<x-filament-panels::page>
    <div class="flex min-w-0 items-start gap-3 rounded-[14px] bg-gray-900 p-[18px] ring-1 ring-hairline">
        <x-filament::icon icon="heroicon-o-clock" class="size-5 shrink-0 text-primary-400" />
        <p class="text-xs leading-relaxed text-gray-400">{{ __('reports.presentation.history_hint') }}</p>
    </div>
    <div class="min-w-0">{{ $this->table }}</div>
</x-filament-panels::page>
