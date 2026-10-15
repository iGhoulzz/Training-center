@props(['name', 'identity' => 0, 'size' => 'sm'])
@php
    $palette = [
        'bg-amber-500/18 text-amber-400', 'bg-blue-400/18 text-blue-400',
        'bg-green-400/18 text-green-400', 'bg-violet-400/20 text-violet-400',
        'bg-pink-400/18 text-pink-400',
    ];
    $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))->join('');
@endphp
<span aria-hidden="true" {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
    $palette[abs((int) $identity) % count($palette)],
    'size-8 text-[12px]' => $size === 'sm',
    'size-[30px] text-[11.5px]' => $size === 'roster',
    'size-9 text-[12.5px]' => $size === 'instructor',
    'size-11 text-[15px]' => $size === 'lg',
    'size-[42px] text-[14px]' => $size === 'preview',
]) }}>{{ mb_strtoupper($initials) }}</span>
