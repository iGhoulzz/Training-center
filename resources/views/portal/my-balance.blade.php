<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.balance_enrollment') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.balance_outstanding') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ __('portal.balance_enrollment_row', ['id' => $row['enrollment_id']]) }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ __('portal.amount_lyd', ['amount' => $row['outstanding']]) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('portal.no_enrollments') }}</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="row" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.balance_total') }}</th>
                    <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">{{ __('portal.amount_lyd', ['amount' => $total]) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament-panels::page>
