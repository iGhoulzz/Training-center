@props(['heading' => null, 'preview' => false])
<aside {{ $attributes->class(['min-w-0 overflow-hidden rounded-[14px] bg-gray-900 ring-1 ring-white/10 xl:sticky xl:[inset-block-start:6rem]']) }}>
    @isset($header)
        <div @class(['border-b border-white/8 bg-linear-to-b from-amber-500/10 to-transparent', 'p-[18px]' => $preview, 'p-5' => ! $preview])>{{ $header }}</div>
    @endisset
    <div @class(['flex flex-col py-4', 'gap-[10px] px-[18px]' => $preview, 'gap-3 px-5' => ! $preview])>
        @if ($heading)<h2 class="text-[13px] font-semibold text-gray-50">{{ $heading }}</h2>@endif
        {{ $slot }}
    </div>
    @isset($footer)
        <div @class(['flex flex-wrap gap-2 border-t border-white/8 py-[14px]', 'px-[18px]' => $preview, 'px-5' => ! $preview])>{{ $footer }}</div>
    @endisset
</aside>
