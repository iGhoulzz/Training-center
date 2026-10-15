@props(['value', 'maximum', 'label', 'tone' => 'primary', 'note' => null, 'percentage' => false])
@php
    // Compare integer half-percent thresholds rather than multiplying monetary
    // aggregates by 100, which could overflow into a float.
    $roundFraction = static function (int $numerator, int $denominator): int {
        for ($percent = 100; $percent > 0; $percent--) {
            $factor = 2 * $percent - 1;
            $threshold = intdiv($denominator, 200) * $factor
                + intdiv(($denominator % 200) * $factor + 199, 200);
            if ($numerator >= $threshold) {
                return $percent;
            }
        }
        return 0;
    };
    $nonnegative = max(0, $value);
    $ratio = $maximum > 0 ? ($nonnegative >= $maximum ? 100 : $roundFraction($nonnegative, $maximum)) : 0;
    $roundedPercent = $percentage && $maximum > 0
        ? intdiv($nonnegative, $maximum) * 100 + $roundFraction($nonnegative % $maximum, $maximum)
        : null;
    $tones = ['primary' => 'text-amber-500', 'success' => 'text-green-400', 'warning' => 'text-amber-400', 'danger' => 'text-red-400', 'neutral' => 'text-gray-400'];
@endphp
<div {{ $attributes->class(['min-w-0 tabular-nums']) }}>
    <div class="mb-[5px] flex justify-between gap-2 text-[11.5px] text-gray-400">
        <span>{{ $label }}</span>
        @if ($percentage && $maximum > 0)
            <span class="font-semibold {{ $tones[$tone] }}">{{ __('enrollment.meter_percent', ['value' => $roundedPercent]) }}</span>
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
