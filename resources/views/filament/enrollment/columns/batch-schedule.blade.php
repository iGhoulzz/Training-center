<div class="flex min-w-0 items-center gap-[10px]">
    @if ($duration > 0)
        <svg aria-hidden="true" viewBox="0 0 100 5" preserveAspectRatio="none" class="h-[5px] min-w-6 shrink basis-[60px] overflow-hidden rounded-full rtl:-scale-x-100">
            <rect width="100" height="5" class="fill-white/8" />
            <rect x="{{ $offset }}" width="{{ $span }}" height="5" rx="3" class="fill-white/22" />
        </svg>
    @endif
    <span class="min-w-0 flex-1 truncate text-[12px] text-gray-400">{{ $range }}</span>
</div>
