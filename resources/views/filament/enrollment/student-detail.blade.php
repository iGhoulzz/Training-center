<x-admin.side-panel>
    <x-slot:header>
        @if ($student)<x-admin.person :name="$student->full_name" :identity="$student->getKey()" :secondary="$student->student_code" size="lg" />
        @else<h2 class="text-[15px] font-semibold">{{ __('enrollment.select_student') }}</h2><p class="text-[12px] text-gray-400">{{ __('enrollment.select_student_hint') }}</p>@endif
    </x-slot:header>
    @if ($student)
        <dl class="flex flex-col gap-3 text-[12.5px]">
            @foreach ($fields as $label => $value)<div class="flex gap-3"><dt class="w-[104px] shrink-0 text-gray-500">{{ $label }}</dt><dd class="min-w-0 flex-1 break-words text-gray-200">{{ $value }}</dd></div>@endforeach
        </dl>
        <div class="flex flex-wrap gap-[7px]">
            @foreach ($jumps as $jump)<x-filament::button tag="a" :href="$jump['url']" color="gray" outlined size="sm">{{ $jump['label'] }} →</x-filament::button>@endforeach
        </div>
        @if ($canReadEnrollments)
            <h3 class="text-[11.5px] font-semibold tracking-[0.05em] text-gray-500 uppercase">{{ __('enrollment.enrollments') }}</h3>
            <div class="flex flex-col gap-2">
                @foreach ($enrollments as $enrollment)
                    <div class="flex items-center gap-[10px] rounded-[10px] border border-white/7 bg-white/4 px-3 py-[10px]">
                        <div class="min-w-0 flex-1">
                            @if ($enrollment['url'])<a href="{{ $enrollment['url'] }}" class="text-[12.5px] font-medium hover:text-amber-400 focus-visible:outline-2 focus-visible:outline-amber-400">{{ $enrollment['batch'] }}</a>@else<span class="text-[12.5px] font-medium">{{ $enrollment['batch'] }}</span>@endif
                            <p class="text-[11.5px] text-gray-500">{{ $enrollment['course'] }}</p>
                        </div>
                        <x-filament::badge :color="$enrollment['color']">{{ $enrollment['status'] }}</x-filament::badge>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
    @if ($student)
        <x-slot:footer>
            {{ $getChildSchema() }}
        </x-slot:footer>
    @endif
</x-admin.side-panel>
