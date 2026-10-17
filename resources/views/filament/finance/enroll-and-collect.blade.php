@if (isset($picker))
    @if ($picker === 'student' && $this->flowStep === 1)
        <div class="flex flex-col gap-3.5">
            <h2 class="text-[15px] font-semibold">{{ __('collect.who_enrolling') }}</h2>
            <label for="student-search" class="sr-only">{{ __('collect.search_students') }}</label>
            <div class="flex items-center gap-3 rounded-[10px] border border-primary-500/35 bg-white/5 px-3.25 py-2.75">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-4 shrink-0 text-gray-400" />
                <input id="student-search" type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="{{ __('collect.search_students') }}" class="min-w-0 flex-1 border-0 bg-transparent text-base text-gray-100 outline-none placeholder:text-gray-500" />
                <span class="shrink-0 text-[11.5px] text-gray-500" aria-live="polite">{{ trans_choice('collect.matches', $this->studentMatches->count(), ['count' => $this->studentMatches->count()]) }}</span>
            </div>
            <fieldset aria-label="{{ __('collect.student_results') }}" class="flex flex-col gap-2">
                @forelse ($this->studentMatches as $student)
                    <label wire:key="student-choice-{{ $student->id }}" @class(['flex cursor-pointer items-center gap-3 rounded-[11px] border px-3.25 py-3 focus-within:ring-2 focus-within:ring-primary-500 hover:bg-white/6', 'border-primary-500/45 bg-primary-500/8' => (string) ($this->data['student_id'] ?? '') === (string) $student->id, 'border-hairline-faint bg-white/3' => (string) ($this->data['student_id'] ?? '') !== (string) $student->id])>
                        <input type="radio" name="student_id" value="{{ $student->id }}" wire:model.live="data.student_id" class="sr-only" />
                        <x-admin.avatar :name="$student->full_name" :identity="$student->id" />
                        <span class="min-w-0 flex-1"><span class="block text-[13.5px] font-medium">{{ $student->full_name }}</span><span class="block text-[11.5px] text-gray-400">{{ __('collect.student_detail', ['code' => $student->student_code, 'phone' => $student->phone ?: __('collect.no_phone')]) }}</span></span>
                        <span class="rounded-full bg-primary-500/12 px-2.25 py-0.75 text-[11.5px] font-semibold text-primary-400">{{ $student->status->label() }}</span>
                        @if ((string) ($this->data['student_id'] ?? '') === (string) $student->id)<span aria-hidden="true" class="text-primary-400">✓</span>@endif
                    </label>
                @empty
                    <p class="py-3 text-sm text-gray-400">{{ __('collect.no_students') }}</p>
                @endforelse
            </fieldset>
            @error('data.student_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
            @if (auth()->user()?->can('create', \App\Domain\Enrollment\Models\Student::class))
                <div class="self-start [&_.fi-btn]:border-dashed">{{ $getAction('createOption') }}</div>
            @endif
        </div>
    @elseif ($picker === 'batch' && $this->flowStep === 2)
        <div class="flex flex-col gap-3.5">
            <h2 class="text-[15px] font-semibold">{{ __('collect.which_intake') }}</h2>
            <label for="batch-search" class="sr-only">{{ __('collect.search_batches') }}</label>
            <input id="batch-search" type="search" wire:model.live.debounce.300ms="batchSearch" placeholder="{{ __('collect.search_batches') }}" class="w-full rounded-[10px] border border-hairline-faint bg-white/5 px-3 py-2.5 text-base focus:border-primary-500 focus:outline-none" />
            <fieldset aria-label="{{ __('collect.batch_options') }}" class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                @forelse ($this->batchChoices as $batch)
                    <label wire:key="batch-choice-{{ $batch->id }}" @class(['flex cursor-pointer flex-col gap-2.25 rounded-xl border p-3.5 focus-within:ring-2 focus-within:ring-primary-500 hover:bg-white/6', 'border-primary-500/45 bg-primary-500/8' => (string) ($this->data['batch_id'] ?? '') === (string) $batch->id, 'border-hairline-faint bg-white/3' => (string) ($this->data['batch_id'] ?? '') !== (string) $batch->id])>
                        <input type="radio" name="batch_id" value="{{ $batch->id }}" wire:model.live="data.batch_id" class="sr-only" />
                        <span class="flex flex-wrap items-center gap-2"><span class="flex-1 text-[13.5px] font-semibold">{{ $batch->code }}</span><span @class(['rounded-full px-2 py-0.75 text-[11px] font-semibold', 'bg-success-500/12 text-success-400' => $batch->status->value === 'active', 'bg-info-500/12 text-info-400' => $batch->status->value !== 'active'])>{{ $batch->status->label() }}</span></span>
                        <span class="text-xs text-gray-400">{{ $batch->course->name() }}</span>
                        <span class="text-[11.5px] text-gray-400">{{ __('collect.batch_dates', ['start' => $batch->start_date?->format('d M Y') ?? __('collect.date_pending'), 'end' => $batch->end_date?->format('d M Y') ?? __('collect.date_pending')]) }}</span>
                        <span class="flex items-center gap-2.25"><progress class="h-1.25 min-w-0 flex-1 accent-primary-500" max="{{ max(1, $batch->capacity, $batch->activeEnrollmentCount()) }}" value="{{ $batch->activeEnrollmentCount() }}" aria-label="{{ __('collect.seats_used') }}"></progress><span @class(['text-[11.5px]', 'text-warning-400' => $batch->isOverCapacity(), 'text-gray-400' => ! $batch->isOverCapacity()])>{{ $batch->capacity > 0 ? __('collect.seats', ['count' => $batch->activeEnrollmentCount(), 'capacity' => $batch->capacity]) : __('collect.open_capacity', ['count' => $batch->activeEnrollmentCount()]) }}</span></span>
                        @if ($batch->isOverCapacity())<span class="text-[11.5px] text-warning-400">{{ __('collect.over_capacity') }}</span>@endif
                        <span class="flex items-center justify-between gap-2 text-[13px] font-semibold text-primary-400"><x-admin.money :amount="$this->batchPrice($batch)" />@if ((string) ($this->data['batch_id'] ?? '') === (string) $batch->id)<span aria-hidden="true">✓</span>@endif</span>
                    </label>
                @empty
                    <p class="py-3 text-sm text-gray-400">{{ __('collect.no_batches') }}</p>
                @endforelse
            </fieldset>
            @error('data.batch_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
        </div>
    @elseif ($picker === 'discount' && $this->flowStep === 3 && auth()->user()?->can('apply_discount'))
        <div class="flex flex-col gap-3.5">
            <h2 class="text-[15px] font-semibold">{{ __('collect.any_discount') }}</h2>
            <label for="discount-search" class="sr-only">{{ __('collect.search_discounts') }}</label>
            <input id="discount-search" type="search" wire:model.live.debounce.300ms="discountSearch" placeholder="{{ __('collect.search_discounts') }}" class="w-full rounded-[10px] border border-hairline-faint bg-white/5 px-3 py-2.5 text-base focus:border-primary-500 focus:outline-none" />
            <fieldset aria-label="{{ __('collect.discount_options') }}" class="flex flex-col gap-2">
                <label @class(['flex cursor-pointer items-center gap-3 rounded-[11px] border p-3.25 focus-within:ring-2 focus-within:ring-primary-500', 'border-primary-500/45 bg-primary-500/8' => blank($this->data['discount_id'] ?? null), 'border-hairline-faint bg-white/3' => filled($this->data['discount_id'] ?? null)])>
                    <input type="radio" name="discount_id" value="" wire:model.live="data.discount_id" class="size-4 accent-primary-500" />
                    <span class="min-w-0 flex-1"><span class="block text-[13.5px] font-medium">{{ __('collect.no_discount') }}</span><span class="block text-[11.5px] text-gray-500">{{ __('collect.full_price') }}</span></span>
                    @if ($this->bill['list'] !== null)<span class="text-[13px] font-semibold"><x-admin.money :amount="$this->bill['list']" /></span>@endif
                </label>
                @foreach ($this->discountChoices as $choice)
                    <label wire:key="discount-choice-{{ $choice['id'] }}" @class(['flex cursor-pointer items-center gap-3 rounded-[11px] border p-3.25 focus-within:ring-2 focus-within:ring-primary-500 hover:bg-white/6', 'border-primary-500/45 bg-primary-500/8' => (string) ($this->data['discount_id'] ?? '') === (string) $choice['id'], 'border-hairline-faint bg-white/3' => (string) ($this->data['discount_id'] ?? '') !== (string) $choice['id']])>
                        <input type="radio" name="discount_id" value="{{ $choice['id'] }}" wire:model.live="data.discount_id" class="size-4 accent-primary-500" />
                        <span class="min-w-0 flex-1"><span class="block text-[13.5px] font-medium">{{ $choice['label'] }}</span><span class="block text-[11.5px] text-gray-500">{{ __('collect.enrolment_discount') }}</span></span>
                        @if ($choice['amount'] !== null)<span class="text-[13px] font-semibold text-success-400"><x-admin.money :amount="$choice['amount']" /></span>@endif
                    </label>
                @endforeach
            </fieldset>
            @error('data.discount_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
        </div>
    @endif
@else
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
                    @endif
                    <div class="mt-auto flex flex-wrap gap-2.25 border-t border-hairline-faint pt-4">
                        @if ($this->flowStep > 1)<x-filament::button color="gray" wire:click="back">{{ __('collect.back') }}</x-filament::button>@endif
                        @if ($this->flowStep < 4)<x-filament::button wire:click="advance" wire:loading.attr="disabled">{{ __('collect.continue') }}</x-filament::button>@else<x-filament::button wire:click="confirm" wire:loading.attr="disabled">{{ auth()->user()?->can('create_payment') ? __('collect.confirm_and_continue') : __('collect.confirm_enrolment') }}</x-filament::button>@endif
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
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.student') }}</dt><dd class="min-w-0 text-end font-medium">{{ $bill['student'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.batch') }}</dt><dd class="min-w-0 text-end font-medium">{{ $bill['batch'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.preview_list_price') }}</dt><dd class="text-[13px] font-medium">@if ($bill['list'] !== null)<x-admin.money :amount="$bill['list']" />@else{{ __('collect.preview_pending') }}@endif</dd></div>
                @if (auth()->user()?->can('apply_discount'))<div class="flex items-baseline justify-between gap-3"><dt class="text-gray-400">{{ __('collect.discount') }}</dt><dd class="text-end text-[13px] font-medium text-success-400">{{ $bill['discount'] }}</dd></div>@endif
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
@endif
