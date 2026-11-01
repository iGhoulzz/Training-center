<x-filament-panels::page>
    <section class="tc-portal-card" aria-label="{{ __('portal.overview_title') }}">
        <div class="tc-portal-card__header">
            <h2 class="tc-portal-card__title">{{ __('portal.overview_title') }}</h2>
        </div>
        <dl class="tc-portal-facts">
            <div class="tc-portal-fact">
                <dt>{{ __('portal.overview_name') }}</dt>
                <dd>{{ $studentName }}</dd>
            </div>
            <div class="tc-portal-fact">
                <dt>{{ __('portal.overview_student_code') }}</dt>
                <dd><bdi>{{ $studentCode }}</bdi></dd>
            </div>
            <div class="tc-portal-fact">
                <dt>{{ __('portal.overview_status') }}</dt>
                <dd><span class="tc-portal-status">{{ $statusLabel }}</span></dd>
            </div>
        </dl>
    </section>
</x-filament-panels::page>
