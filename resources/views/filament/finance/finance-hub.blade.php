<x-filament-panels::page>
    {{-- Navigation only: one section per group that has a visible tile. Which
         tiles exist, and for whom, is decided by FinanceHub (see its class
         docblock), so this view holds no permission check of its own. The data-
         attributes are what FinanceHubTest reads the page back by. --}}
    @foreach ($groups as $group)
        <x-filament::section
            :heading="$group->heading"
            :description="$group->description"
            data-finance-hub-group="{{ $group->id }}"
        >
            <ul>
                @foreach ($group->tiles as $tile)
                    <li>
                        <x-filament::link
                            :href="$tile->url"
                            data-finance-hub-tile="{{ $tile->id }}"
                        >
                            {{ $tile->label }}
                        </x-filament::link>

                        <p>{{ $tile->description }}</p>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
