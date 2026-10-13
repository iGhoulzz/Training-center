<article @class(['flex flex-col gap-3 rounded-xl border border-white/8 bg-white/3 p-4', 'opacity-60' => $departed])>
    <div class="flex items-center gap-[11px]">
        <x-admin.person :name="$name" :identity="$identity" :secondary="$jobTitle" size="instructor" />
        <x-filament::badge :color="$departed ? 'gray' : 'success'">{{ $state }}</x-filament::badge>
    </div>
    <x-admin.meter :value="$hours" :maximum="$totalHours" :label="__('enrollment.assigned_hours_value', ['hours' => $hours])" />
</article>
