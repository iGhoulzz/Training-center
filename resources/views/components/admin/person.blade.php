@props(['name', 'identity' => 0, 'secondary' => null, 'url' => null, 'size' => 'sm'])
<div {{ $attributes->class(['flex min-w-0 items-center gap-[11px]']) }}>
    <x-admin.avatar :name="$name" :identity="$identity" :size="$size" />
    <div class="min-w-0 flex-1">
        @if ($url)
            <a href="{{ $url }}" x-on:click.stop @class(['block truncate text-start text-gray-50 hover:text-amber-400 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400', 'text-[15px] font-semibold' => $size === 'lg', 'text-[13.5px] font-semibold' => $size === 'instructor', 'text-[13.5px] font-medium' => ! in_array($size, ['lg', 'instructor'])])>{{ $name }}</a>
        @else
            <span @class(['block truncate text-gray-50', 'text-[15px] font-semibold' => $size === 'lg', 'text-[14.5px] font-semibold' => $size === 'preview', 'text-[13.5px] font-semibold' => $size === 'instructor', 'text-[13.5px] font-medium' => ! in_array($size, ['lg', 'instructor', 'preview'])])>{{ $name }}</span>
        @endif
        @if ($secondary)
            <span class="block truncate text-[11.5px] text-gray-500">{{ $secondary }}</span>
        @endif
    </div>
</div>
