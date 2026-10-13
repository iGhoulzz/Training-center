<article class="flex flex-col gap-[13px] p-[17px]" data-admin-course-card>
    <div class="flex items-start gap-[10px]">
        <div class="min-w-0 flex-1">
            <h2 class="text-[14.5px] font-semibold text-gray-50">{{ $name }}</h2>
            <p class="font-mono text-[11.5px] text-gray-500">{{ $code }} · {{ __('enrollment.hours_short', ['hours' => $hours]) }}</p>
        </div>
        <x-filament::badge :color="$active ? 'success' : 'gray'">{{ $active ? __('enrollment.course_active') : __('enrollment.course_retired') }}</x-filament::badge>
    </div>
    @if ($price !== null)
        <div class="flex items-baseline gap-[7px]"><x-admin.money :amount="$price" class="text-[19px] font-semibold tracking-[-0.02em] text-amber-400" /><span class="text-[11.5px] text-gray-500">{{ __('enrollment.default_price_note') }}</span></div>
    @endif
    @if ($revenue !== null)
        <div><x-admin.money :amount="$revenue" class="text-[11.5px] text-gray-400" /><span class="ms-1 text-[11.5px] text-gray-400">{{ __('enrollment.this_year', ['year' => $year]) }}</span>
            <x-admin.meter :value="$revenue->dirham" :maximum="$revenueMaximum" :label="__('enrollment.revenue_this_year')" class="mt-[5px]" />
        </div>
    @endif
    <div class="flex flex-wrap gap-[7px] border-t border-white/7 pt-[11px]">
        @if ($batchesUrl)
            <x-filament::button tag="a" :href="$batchesUrl" color="gray" outlined size="sm" x-on:click.stop>{{ __('enrollment.batches_count', ['count' => $batches]) }} →</x-filament::button>
        @else
            <span class="rounded-[8px] border border-white/10 px-[10px] py-[6px] text-[11.5px] text-gray-400">{{ __('enrollment.batches_count', ['count' => $batches]) }}</span>
        @endif
        @if ($enrolled !== null)
        @if ($enrolledUrl)
            <x-filament::button tag="a" :href="$enrolledUrl" color="gray" outlined size="sm" x-on:click.stop>{{ __('enrollment.enrolled_students', ['count' => $enrolled]) }} →</x-filament::button>
        @else
            <span class="rounded-[8px] border border-white/10 px-[10px] py-[6px] text-[11.5px] text-gray-400">{{ __('enrollment.enrolled_students', ['count' => $enrolled]) }}</span>
        @endif
        @endif
    </div>
</article>
