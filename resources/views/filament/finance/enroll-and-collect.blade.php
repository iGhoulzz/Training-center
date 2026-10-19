<x-filament-panels::page>
    @php($bill = $this->bill)
    <div class="flex min-w-0 flex-wrap items-start gap-4 [&_.fi-input]:text-base! [&_.fi-select-input]:text-base! [&_.fi-select-input-btn]:text-base!">
        <div class="flex min-w-0 flex-[1_1_380px] flex-col gap-4">
            {{ $this->form }}

            @if ($this->collectionChargeId !== null)
                <section aria-label="{{ __('collect.take_payment') }}" class="flex min-w-0 flex-col gap-4 rounded-[14px] bg-gray-900 p-5 ring-1 ring-hairline">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">{{ __('collect.take_payment') }}</h2>
                        <span @class(['text-[11.5px] font-semibold', 'text-success-400' => $this->tendersBalanced, 'text-warning-400' => ! $this->tendersBalanced]) aria-live="polite">
                            {{ $this->tendersBalanced ? __('collect.balanced') : __('collect.unbalanced') }}
                        </span>
                    </div>
                    {{ $this->collectForm }}
                </section>
            @endif
        </div>

        <aside aria-label="{{ __('collect.bill') }}" class="min-w-0 flex-[1_1_300px] overflow-hidden rounded-[14px] bg-gray-900 ring-1 ring-hairline lg:sticky lg:top-24 lg:max-w-90">
            <h2 class="border-b border-hairline-faint px-4.5 py-4 text-sm font-semibold">{{ __('collect.bill') }}</h2>
            <dl class="flex flex-col gap-2.75 px-4.5 py-4 text-[12.5px]">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-gray-400">{{ __('collect.student') }}</dt>
                    <dd class="min-w-0 text-end font-medium wrap-anywhere">{{ $bill['student'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-gray-400">{{ __('collect.batch') }}</dt>
                    <dd class="min-w-0 text-end font-medium wrap-anywhere">{{ $bill['batch'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-gray-400">{{ __('collect.preview_list_price') }}</dt>
                    <dd class="text-[13px] font-medium">@if ($bill['list'] !== null)<x-admin.money :amount="$bill['list']" />@else{{ __('collect.preview_pending') }}@endif</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-gray-400">{{ __('collect.discount') }}</dt>
                    <dd class="text-end text-[13px] font-medium text-success-400">{{ $bill['discount'] }}</dd>
                </div>
            </dl>
            <div class="mx-4.5 flex flex-wrap items-baseline justify-between gap-3 border-t border-hairline-faint pt-3.5 pb-4">
                <span class="text-[13px] font-semibold">{{ __('collect.preview_final_amount') }}</span>
                <span class="text-[22px] font-semibold tracking-[-0.02em] text-primary-400">@if ($bill['amount'] !== null)<x-admin.money :amount="$bill['amount']" />@else{{ __('collect.not_chosen') }}@endif</span>
                @if ($bill['reference'])<span class="w-full text-end text-[11.5px] text-gray-400">{{ $bill['reference'] }}</span>@endif
                @if ($bill['receipt'])<span class="w-full text-end text-[11.5px] text-gray-400">{{ $bill['receipt'] }}</span>@endif
            </div>
            <p class="border-t border-hairline-faint bg-white/3 px-4.5 py-3.5 text-[11.5px] text-gray-400">{{ __('collect.first_collection_hint') }}</p>
        </aside>
    </div>
</x-filament-panels::page>
