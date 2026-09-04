<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <dl class="divide-y divide-gray-200 dark:divide-white/10">
            <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('portal.overview_name') }}</dt>
                <dd class="text-sm text-gray-950 sm:col-span-2 dark:text-white">{{ $studentName }}</dd>
            </div>

            <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('portal.overview_student_code') }}</dt>
                <dd class="text-sm text-gray-950 sm:col-span-2 dark:text-white">{{ $studentCode }}</dd>
            </div>

            <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('portal.overview_status') }}</dt>
                <dd class="text-sm text-gray-950 sm:col-span-2 dark:text-white">{{ $statusLabel }}</dd>
            </div>
        </dl>
    </div>
</x-filament-panels::page>
