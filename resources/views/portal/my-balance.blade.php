<x-filament-panels::page>
    <section class="tc-portal-card" aria-label="{{ __('portal.my_balance_title') }}">
        <div class="tc-portal-card__header">
            <h2 class="tc-portal-card__title">{{ __('portal.my_balance_title') }}</h2>
        </div>
        <div class="tc-portal-table-scroll">
            <table class="tc-portal-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('portal.balance_enrollment') }}</th>
                    <th scope="col">{{ __('portal.enrollments_course') }}</th>
                    <th scope="col">{{ __('portal.enrollments_batch') }}</th>
                    <th scope="col">{{ __('portal.balance_outstanding') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td data-label="{{ __('portal.balance_enrollment') }}">{{ __('portal.balance_enrollment_row', ['id' => $row['enrollment_id']]) }}</td>
                        <td data-label="{{ __('portal.enrollments_course') }}">{{ $row['course'] }}</td>
                        <td data-label="{{ __('portal.enrollments_batch') }}"><bdi>{{ $row['batch_code'] }}</bdi></td>
                        <td data-label="{{ __('portal.balance_outstanding') }}" class="tc-portal-money">{{ __('portal.amount_lyd', ['amount' => $row['outstanding']]) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="tc-portal-empty">{{ __('portal.no_enrollments') }}</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3">{{ __('portal.balance_total') }}</th>
                    <td class="tc-portal-money">{{ __('portal.amount_lyd', ['amount' => $total]) }}</td>
                </tr>
            </tfoot>
            </table>
        </div>
    </section>
</x-filament-panels::page>
