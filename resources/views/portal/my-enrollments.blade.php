<x-filament-panels::page>
    <section class="tc-portal-card" aria-label="{{ __('portal.my_enrollments_title') }}">
        <div class="tc-portal-card__header">
            <h2 class="tc-portal-card__title">{{ __('portal.my_enrollments_title') }}</h2>
        </div>
        <div class="tc-portal-table-scroll">
            <table class="tc-portal-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('portal.enrollments_course') }}</th>
                    <th scope="col">{{ __('portal.enrollments_batch') }}</th>
                    <th scope="col">{{ __('portal.enrollments_enrolled_on') }}</th>
                    <th scope="col">{{ __('portal.enrollments_completed_on') }}</th>
                    <th scope="col">{{ __('portal.enrollments_status') }}</th>
                    @if ($showsCertificates)
                        <th scope="col">{{ __('portal.enrollments_certificate_reference') }}</th>
                        <th scope="col">{{ __('portal.enrollments_certificate_status') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td data-label="{{ __('portal.enrollments_course') }}">{{ $row['course'] }}</td>
                        <td data-label="{{ __('portal.enrollments_batch') }}"><bdi>{{ $row['batch_code'] }}</bdi></td>
                        <td data-label="{{ __('portal.enrollments_enrolled_on') }}">{{ $row['enrolled_at'] }}</td>
                        <td data-label="{{ __('portal.enrollments_completed_on') }}">{{ $row['completed_at'] ?? __('portal.not_completed') }}</td>
                        <td data-label="{{ __('portal.enrollments_status') }}"><span class="tc-portal-status">{{ $row['status_label'] }}</span></td>
                        @if ($showsCertificates)
                            <td data-label="{{ __('portal.enrollments_certificate_reference') }}"><bdi>{{ $row['certificate_reference'] ?? __('portal.no_certificate') }}</bdi></td>
                            <td data-label="{{ __('portal.enrollments_certificate_status') }}">{{ $row['certificate_status_label'] ?? __('portal.no_certificate') }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showsCertificates ? 7 : 5 }}" class="tc-portal-empty">{{ __('portal.no_enrollments') }}</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
