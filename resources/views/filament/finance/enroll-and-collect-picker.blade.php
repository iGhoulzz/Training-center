@if ($picker === 'student' && $this->flowStep === 1)
    <div class="flex flex-col gap-3.5">
        <h2 class="text-[15px] font-semibold">{{ __('collect.who_enrolling') }}</h2>
        <label for="student-search" class="sr-only">{{ __('collect.search_students') }}</label>
        <div class="flex items-center gap-3 rounded-[10px] border border-primary-500/35 bg-white/5 px-3.25 py-2.75 focus-within:ring-2 focus-within:ring-primary-500">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-4 shrink-0 text-gray-400" />
            <input id="student-search" type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="{{ __('collect.search_students') }}" class="min-w-0 flex-1 border-0 bg-transparent text-base text-gray-100 outline-none placeholder:text-gray-500" />
            <span class="shrink-0 text-[11.5px] text-gray-500" aria-live="polite">{{ trans_choice('collect.matches', $this->studentMatches->count(), ['count' => $this->studentMatches->count()]) }}</span>
        </div>
        <fieldset aria-label="{{ __('collect.student_results') }}" class="flex flex-col gap-2">
            @forelse ($this->studentMatches as $student)
                <label wire:key="student-choice-{{ $student->id }}" @class(['flex cursor-pointer items-center gap-3 rounded-[11px] border px-3.25 py-3 focus-within:ring-2 focus-within:ring-primary-500 hover:bg-white/6', 'border-primary-500/45 bg-primary-500/8' => (string) ($this->data['student_id'] ?? '') === (string) $student->id, 'border-hairline-faint bg-white/3' => (string) ($this->data['student_id'] ?? '') !== (string) $student->id])>
                    <input type="radio" name="student_id" value="{{ $student->id }}" wire:model.live="data.student_id" class="sr-only" />
                    <x-admin.avatar :name="$student->full_name" :identity="$student->id" />
                    <span class="min-w-0 flex-1"><span class="block text-[13.5px] font-medium">{{ $student->full_name }}</span><span class="block text-[11.5px] text-gray-400">@can('view', $student){{ __('collect.student_detail', ['code' => $student->student_code, 'phone' => $student->phone ?: __('collect.no_phone')]) }}@else{{ $student->student_code }}@endcan</span></span>
                    @can('view', $student)
                        <span class="rounded-full bg-primary-500/12 px-2.25 py-0.75 text-[11.5px] font-semibold text-primary-400">{{ $student->status->label() }}</span>
                    @endcan
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
                    <span class="text-[11.5px] text-gray-400">{{ __('collect.batch_dates', ['start' => $batch->start_date?->toDateString() ?? __('collect.date_pending'), 'end' => $batch->end_date?->toDateString() ?? __('collect.date_pending')]) }}</span>
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
                    @if ($choice['savings'] !== null)<span class="text-[13px] font-semibold text-success-400" aria-label="{{ __('collect.discount_savings') }}">{{ __('collect.discount_savings_amount', ['amount' => $choice['savings']->toDecimal()]) }}</span>@endif
                </label>
            @endforeach
        </fieldset>
        @error('data.discount_id')<p role="alert" class="text-sm text-danger-400">{{ $message }}</p>@enderror
    </div>
@endif
