<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-400">{{ __('certificates.queue_hint') }}</p>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
