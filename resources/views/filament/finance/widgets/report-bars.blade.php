<x-filament-widgets::widget class="fi-wi-report-bars">
    <x-filament::section :heading="$heading" :description="$description">
        <p class="mb-4 text-[12.5px] text-gray-400 tabular-nums" data-report-period>{{ $period }}</p>
        @if ($rows === [])
            <p class="py-6 text-center text-gray-400">{{ __('reports.empty') }}</p>
        @else
            <x-admin.report-bars :rows="$rows" :signed="$signed" />
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
