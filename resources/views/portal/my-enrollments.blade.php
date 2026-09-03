<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_course') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_batch') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_enrolled_on') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_completed_on') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_status') }}</th>
                    @if ($showsCertificates)
                        <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_certificate_reference') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('portal.enrollments_certificate_status') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['course'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['batch_code'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['enrolled_at'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['completed_at'] ?? __('portal.not_completed') }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['status_label'] }}</td>
                        @if ($showsCertificates)
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['certificate_reference'] ?? __('portal.no_certificate') }}</td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['certificate_status_label'] ?? __('portal.no_certificate') }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showsCertificates ? 7 : 5 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('portal.no_enrollments') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
