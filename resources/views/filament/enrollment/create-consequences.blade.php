<x-admin.side-panel :heading="$heading" preview>
    @if ($name !== null)
        <x-slot:header>
            <x-admin.person :name="$name" :identity="0" :secondary="$secondary" size="preview" />
        </x-slot:header>
    @endif
    @foreach ($facts as $label => $value)
        <div class="flex items-baseline gap-[10px] text-[12px]"><span class="flex-1 text-gray-400">{{ $label }}</span>
            @if ($value instanceof \App\Domain\Finance\Support\Money)
                <x-admin.money :amount="$value" class="text-[13px] text-amber-400" />
            @else
                <span class="text-[13px] text-gray-50 tabular-nums">{{ $value }}</span>
            @endif
        </div>
    @endforeach
    @foreach ($notices as $notice)<p @class(['rounded-[10px] border px-[11px] py-[10px] text-[11.5px] leading-[1.45] text-gray-300', 'border-green-400/22 bg-green-400/8' => $notice['tone'] === 'success', 'border-amber-400/22 bg-amber-400/8' => $notice['tone'] === 'warning', 'border-white/8 bg-white/3' => $notice['tone'] === 'neutral'])>{{ $notice['text'] }}</p>@endforeach
    @if ($getChildSchema()->getComponents())
        <x-slot:footer>
            {{ $getChildSchema() }}
        </x-slot:footer>
    @endif
</x-admin.side-panel>
