@props(['value', 'maximum', 'label', 'tone' => 'primary', 'note' => null, 'percentage' => false])
@php
    $ratio = $maximum > 0 ? intdiv(min(max(0, $value), $maximum) * 100, $maximum) : 0;
    $tones = ['primary' => 'text-amber-500', 'success' => 'text-green-400', 'warning' => 'text-amber-400', 'danger' => 'text-red-400', 'neutral' => 'text-gray-400'];
@endphp
<div {{ $attributes->class(['min-w-0 tabular-nums']) }}>
    <div class="mb-[5px] flex justify-between gap-2 text-[11.5px] text-gray-400">
        <span>{{ $label }}</span>
        @if ($percentage && $maximum > 0)
            <span class="font-semibold {{ $tones[$tone] }}">{{ __('enrollment.meter_percent', ['value' => intdiv(max(0, $value) * 100, $maximum)]) }}</span>
        @endif
    </div>
    <svg role="img" aria-label="{{ $label }}" viewBox="0 0 100 6" preserveAspectRatio="none" class="block h-[6px] w-full overflow-hidden rounded-full {{ $tones[$tone] }} rtl:-scale-x-100">
        <rect width="100" height="6" class="fill-white/8" />
        <rect width="{{ $ratio }}" height="6" rx="3" class="fill-current" />
    </svg>
    @if ($note)
        <span class="mt-1 block text-[11px] {{ $tones[$tone] }}">{{ $note }}</span>
    @endif
</div>
