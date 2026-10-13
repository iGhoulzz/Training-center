<x-filament-panels::page>
    <section class="rounded-[14px] border border-amber-400/28 bg-amber-400/7 px-[18px] py-4">
        <h2 class="text-[13.5px] font-semibold text-amber-400">{{ __('certificates.queue_title') }}</h2>
        <p class="mt-[3px] text-[12px] text-gray-400">{{ __('certificates.queue_hint') }}</p>
    </section>
    {{ $this->table }}
</x-filament-panels::page>
