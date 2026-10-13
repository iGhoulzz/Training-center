@if ($percentage)
    <x-admin.meter :value="$value" :maximum="$maximum" :label="$label" :tone="$tone" :note="$note" :percentage="$percentage" />
@else
    <div class="tabular-nums"><span @class(['text-[12.5px]', 'text-amber-400' => $tone === 'warning', 'text-gray-300' => $tone !== 'warning'])>{{ $label }}</span>
        @if ($note)<span @class(['mt-[3px] block text-[11px]', 'text-amber-400' => $tone === 'warning', 'text-gray-500' => $tone !== 'warning'])>{{ $note }}</span>@endif
    </div>
@endif
