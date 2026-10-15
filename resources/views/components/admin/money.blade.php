@props(['amount'])
@php
    $decimal = ($amount instanceof \App\Domain\Finance\Support\Money ? $amount : \App\Domain\Finance\Support\Money::fromDecimal((string) $amount))->toDecimal();
    [$whole, $fraction] = explode('.', $decimal);
    $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', __('enrollment.money_separator'), $whole).'.'.$fraction;
@endphp
<span {{ $attributes->class(['whitespace-nowrap tabular-nums']) }}>{{ __('enrollment.money_lyd', ['amount' => $formatted]) }}</span>
