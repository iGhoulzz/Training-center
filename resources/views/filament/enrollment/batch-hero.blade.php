<section class="flex flex-wrap items-center gap-7 rounded-2xl bg-linear-to-b from-amber-500/9 via-gray-900 to-gray-900 px-6 py-[22px] ring-1 ring-white/10" data-admin-batch-hero>
    <div class="min-w-[230px] flex-1">
        <div class="flex flex-wrap items-center gap-[10px]"><h2 class="text-[26px] font-semibold tracking-[-0.025em]">{{ $code }}</h2><x-filament::badge :color="$statusColor">{{ $status }}</x-filament::badge></div>
        <p class="mt-[5px] text-[13px] text-gray-400">{{ $course }} · {{ $dates }} · {{ __('enrollment.hours_short', ['hours' => $totalHours]) }}</p>
    </div>
    <div class="min-w-[118px]"><p class="text-[11.5px] font-medium text-gray-400">{{ __('enrollment.enrolment_load') }}</p><p class="mt-[3px] text-[21px] font-semibold tracking-[-0.02em] tabular-nums">{{ $seatLabel }}</p>@if ($over)<p class="text-[11.5px] text-amber-400">{{ __('enrollment.over_capacity_warning') }}</p>@endif</div>
    <div class="min-w-[118px]"><p class="text-[11.5px] font-medium text-gray-400">{{ __('enrollment.hour_allocation') }}</p><p class="mt-[3px] text-[21px] font-semibold tracking-[-0.02em] tabular-nums">{{ $hourLabel }}</p><p @class(['text-[11.5px]', 'text-amber-400' => $mismatch, 'text-gray-500' => ! $mismatch])>{{ $mismatch ? __('enrollment.hour_mismatch_hint') : __('enrollment.hours_matched') }}</p></div>
    @if ($outstanding !== null)<div class="min-w-[118px]"><p class="text-[11.5px] font-medium text-gray-400">{{ __('enrollment.outstanding') }}</p><x-admin.money :amount="$outstanding" class="mt-[3px] block text-[21px] font-semibold tracking-[-0.02em] text-amber-400" /></div>@endif
    <div class="flex gap-2">{{ $getChildSchema() }}</div>
</section>
