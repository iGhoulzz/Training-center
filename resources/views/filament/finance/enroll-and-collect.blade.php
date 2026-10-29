<x-filament-panels::page>
    @php
        $bill = $this->bill;
        $steps = [1 => ['label' => __('collect.step_student'), 'value' => $bill['student']], 2 => ['label' => __('collect.step_batch'), 'value' => $bill['batch']]];
        if (auth()->user()?->can('apply_discount')) {
            $steps[3] = ['label' => __('collect.step_discount'), 'value' => $bill['discount']];
        }
        $steps[4] = ['label' => __('collect.step_collect'), 'value' => $bill['receipt'] ?? ($bill['amount'] === null ? __('collect.not_chosen') : __('collect.amount_lyd', ['amount' => $bill['amount']->toDecimal()]))];
    @endphp
    <div class="flex min-w-0 flex-wrap items-start gap-4 [&_.fi-input]:text-base! [&_.fi-select-input]:text-base! [&_.fi-select-input-btn]:text-base!">
        <div class="flex min-w-0 flex-[1_1_380px] flex-col gap-4">
            <nav aria-label="{{ __('collect.enrolment_steps') }}">
                <ol class="flex flex-wrap gap-2">
                    @foreach ($steps as $number => $step)
                        <li class="min-w-32.5 flex-1">
                            <button type="button" wire:click="goToStep({{ $number }})" @disabled($number >= $this->flowStep || $this->collectionChargeId !== null) @if ($number === $this->flowStep) aria-current="step" @endif @class(['flex w-full items-center gap-2.5 rounded-[11px] border px-3.25 py-2.75 text-start focus-visible:outline-2 focus-visible:outline-primary-500', 'border-primary-500/45 bg-primary-500/12' => $number === $this->flowStep, 'border-hairline-faint bg-white/3' => $number !== $this->flowStep])>
                                <span @class(['flex size-6 shrink-0 items-center justify-center rounded-full text-[11.5px] font-bold', 'bg-primary-500 text-gray-950' => $number === $this->flowStep, 'bg-success-500/15 text-success-400' => $number < $this->flowStep, 'bg-white/8 text-gray-500' => $number > $this->flowStep])>{{ $number < $this->flowStep ? '✓' : $loop->iteration }}</span>
                                <span class="min-w-0"><span class="block text-[12.5px] font-semibold">{{ $step['label'] }}</span><span class="block truncate text-[11px] text-gray-500">{{ $step['value'] }}</span></span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </nav>
            <section class="flex min-h-82.5 min-w-0 flex-col gap-4 rounded-[14px] bg-gray-900 p-5 ring-1 ring-hairline" aria-label="{{ $steps[$this->flowStep]['label'] ?? __('collect.step_collect') }}">
                @if ($this->collectionChargeId === null)
                    {{ $this->form }}
                    @if ($this->flowStep === 4)
                        <h2 class="text-[15px] font-semibold">{{ __('collect.review_enrolment') }}</h2>
                        <p class="text-sm text-gray-400">{{ __('collect.confirm_before_payment') }}</p>
                        <div class="rounded-xl border border-primary-500/30 bg-primary-500/8 p-4 text-[22px] font-semibold text-primary-400">@if ($bill['amount'] !== null)<x-admin.money :amount="$bill['amount']" />@endif</div>
                        @error('data.student_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
                        @error('data.batch_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
                        @error('data.discount_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
                    @endif
                    <div class="mt-auto flex flex-wrap gap-2.25 border-t border-hairline-faint pt-4">
                        @if ($this->flowStep > 1)<x-filament::button color="gray" wire:click="back">{{ __('collect.back') }}</x-filament::button>@endif
                        @if ($this->flowStep < 4)<x-filament::button wire:click="advance" wire:loading.attr="disabled">{{ __('collect.continue') }}</x-filament::button>@else<x-filament::button wire:click="confirm" wire:loading.attr="disabled">{{ auth()->user()?->can('create_payment') ? __('collect.confirm_and_continue') : __('collect.confirm_enrolment') }}</x-filament::button>@endif
                    </div>
                @elseif ($payment = $this->recordedPayment)
                    <div class="flex min-w-0 flex-col gap-4" data-payment-recorded>
                        <h2 class="text-[15px] font-semibold text-success-400">{{ __('collect.payment_recorded') }}</h2>
                        <dl class="flex flex-col gap-2.75 text-[12.5px]">
                            <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.recorded_receipt') }}</dt><dd class="min-w-0 text-end font-medium wrap-anywhere">{{ $payment->reference }}</dd></div>
                            <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.recorded_amount') }}</dt><dd class="text-[15px] font-semibold text-primary-400"><x-admin.money :amount="$payment->tenders_sum_amount" /></dd></div>
                        </dl>
                        <div>
                            <h3 class="mb-2 text-[12.5px] text-gray-400">{{ __('collect.recorded_tenders') }}</h3>
                            <ul class="flex flex-col gap-2 text-[12.5px]">
                                @foreach ($payment->tenders as $tender)
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-[11px] bg-fill-subtle px-3 py-2">
                                        <span class="font-medium">{{ $tender->method->label() }}</span>
                                        <x-admin.money :amount="$tender->amount" class="font-medium" />
                                        @if (filled($tender->external_reference))<span class="w-full text-[11.5px] text-gray-400">{{ __('collect.recorded_tender_reference', ['reference' => $tender->external_reference]) }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="mt-auto flex flex-wrap gap-2.25 border-t border-hairline-faint pt-4">
                            @if ($this->canPrintReceipt())
                                <x-filament::button tag="a" :href="route('finance.receipts.print', $payment)" target="_blank" rel="noopener noreferrer" icon="heroicon-o-printer" data-print-receipt>{{ __('payments.print_receipt') }}</x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="finishCollection">{{ __('collect.done') }}</x-filament::button>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">{{ __('collect.take_payment') }}</h2>
                        <span @class(['text-[11.5px] font-semibold', 'text-success-400' => $this->tendersBalanced, 'text-warning-400' => ! $this->tendersBalanced]) aria-live="polite">{{ $this->tendersBalanced ? __('collect.balanced') : __('collect.unbalanced') }}</span>
                    </div>
                    <p class="text-[12.5px] text-gray-400">{{ __('collect.bill_issued') }}</p>
                    {{ $this->collectForm }}
                @endif
            </section>
        </div>
        <aside aria-label="{{ __('collect.bill') }}" class="min-w-0 flex-[1_1_300px] overflow-hidden rounded-[14px] bg-gray-900 ring-1 ring-hairline lg:sticky lg:top-24 lg:max-w-90">
            <h2 class="border-b border-hairline-faint px-4.5 py-4 text-sm font-semibold">{{ __('collect.bill') }}</h2>
            <dl class="flex flex-col gap-2.75 px-4.5 py-4 text-[12.5px]" aria-live="polite">
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.student') }}</dt><dd class="min-w-0 text-end font-medium wrap-anywhere">{{ $bill['student'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.batch') }}</dt><dd class="min-w-0 text-end font-medium wrap-anywhere">{{ $bill['batch'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.preview_list_price') }}</dt><dd class="text-[13px] font-medium">@if ($bill['list'] !== null)<x-admin.money :amount="$bill['list']" />@else{{ __('collect.preview_pending') }}@endif</dd></div>
                @if (auth()->user()?->can('apply_discount'))<div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.discount') }}</dt><dd class="text-end text-[13px] font-medium text-success-400">{{ $bill['discount'] }}</dd></div>@endif
                @if ($bill['paid'] !== null)<div class="flex items-baseline justify-between gap-3" data-bill-paid><dt class="text-gray-400">{{ __('collect.bill_paid_so_far') }}</dt><dd class="text-[13px] font-medium"><x-admin.money :amount="$bill['paid']" /></dd></div>@endif
                @if ($bill['remaining'] !== null)<div class="flex items-baseline justify-between gap-3" data-bill-remaining><dt class="text-gray-400">{{ __('collect.bill_remaining') }}</dt><dd class="text-[13px] font-semibold text-primary-400"><x-admin.money :amount="$bill['remaining']" /></dd></div>@endif
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
