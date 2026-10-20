@props(['rows', 'signed' => false])
@php
    use App\Domain\Finance\Support\Money;

    // Same integer ratio as x-admin.meter: compare half-percent thresholds on dirham
    // integers rather than multiplying monetary values, so no float touches money.
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
    $tones = ['primary' => 'text-amber-500', 'success' => 'text-green-400', 'warning' => 'text-amber-400', 'danger' => 'text-red-400', 'neutral' => 'text-gray-400'];
    $bars = collect($rows)->map(fn (array $row): array => [
        'label' => (string) $row['label'],
        'dirham' => $row['value'] instanceof Money ? $row['value']->dirham : (int) $row['value'],
        'tone' => $row['tone'] ?? 'primary',
        'emphasis' => (bool) ($row['emphasis'] ?? false),
    ])->values();
    // A negative value always keeps its minus, signed chart or not: a wage total can
    // be negative when deductions exceed pay, and the table under the chart says so.
    // Not abs(): it returns a float for PHP_INT_MIN, and no float touches money here.
    $magnitudeOf = static fn (int $dirham): int => $dirham < 0 ? -$dirham : $dirham;
    // Every bar is scaled to the largest absolute value in the chart.
    $maximum = (int) $bars->max(fn (array $bar): int => $magnitudeOf($bar['dirham']));
@endphp
<ul {{ $attributes->class(['flex min-w-0 flex-col gap-3 tabular-nums']) }}>
    @foreach ($bars as $bar)
        @php
            $magnitude = $magnitudeOf($bar['dirham']);
            $ratio = $maximum > 0 ? ($magnitude >= $maximum ? 100 : $roundFraction($magnitude, $maximum)) : 0;
        @endphp
        <li @class([
            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_minmax(0,auto)]',
            'border-t border-hairline pt-3 font-semibold' => $bar['emphasis'],
        ]) data-report-bar>
            <span class="min-w-0 truncate text-[12.5px] text-gray-300">{{ $bar['label'] }}</span>
            <svg role="img" aria-label="{{ $bar['label'] }}" viewBox="0 0 100 6" preserveAspectRatio="none" data-bar-tone="{{ $bar['tone'] }}" class="order-3 col-span-2 block h-2.5 w-full overflow-hidden rounded-full sm:order-2 sm:col-span-1 {{ $tones[$bar['tone']] }} rtl:-scale-x-100">
                <rect width="100" height="6" class="fill-white/8" />
                <rect width="{{ $ratio }}" height="6" rx="3" class="fill-current" />
            </svg>
            <span dir="ltr" class="order-2 whitespace-nowrap text-end text-[12.5px] sm:order-3 {{ $signed ? $tones[$bar['tone']] : 'text-gray-50' }}" data-bar-value>@if ($signed){{ $bar['dirham'] < 0 ? __('reports.charts.sign_negative') : ($bar['dirham'] > 0 ? __('reports.charts.sign_positive') : '') }}@elseif ($bar['dirham'] < 0){{ __('reports.charts.sign_negative') }}@endif<x-admin.money :amount="Money::fromDirham($magnitude)" /></span>
        </li>
    @endforeach
</ul>
