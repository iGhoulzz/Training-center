<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('verify.page_title') }}</title>
    <style>
        /*
         * Self-hosted, inline, and the only styling this page carries — no
         * third-party font, script, analytics or stylesheet may touch this
         * surface (design section 6.5). Logical properties only, per
         * docs/ENGINEERING.md.
         */
        :root {
            color-scheme: light;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            font-family: system-ui, sans-serif;
            color: #111827;
            padding-block: 2rem;
            padding-inline: 1rem;
        }

        main {
            width: 100%;
            max-width: 32rem;
            background: #ffffff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.75rem;
        }

        h1 {
            margin-block: 0 0.5rem;
            font-size: 1.25rem;
        }

        .status-message {
            margin-block: 0 1.25rem;
            font-weight: 600;
        }

        dl {
            margin: 0;
        }

        .field {
            padding-block: 0.625rem;
            border-block-start: 1px solid #e5e7eb;
        }

        .field:first-of-type {
            border-block-start: none;
        }

        dt {
            font-size: 0.8rem;
            color: #6b7280;
            text-align: start;
        }

        dd {
            margin: 0;
            text-align: start;
        }

        .actions {
            margin-block-start: 1.5rem;
        }

        a {
            color: #111827;
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('verify.show_heading') }}</h1>
        <p class="status-message">{{ __($statusMessageKey) }}</p>

        <dl>
            <div class="field">
                <dt>{{ __('verify.field_status') }}</dt>
                <dd>{{ $view->status->label() }}</dd>
            </div>
            <div class="field">
                <dt>{{ __('verify.field_student') }}</dt>
                <dd>{{ $view->studentName }}</dd>
            </div>
            <div class="field">
                <dt>{{ __('verify.field_course') }}</dt>
                <dd>{{ $view->courseName }}</dd>
            </div>
            <div class="field">
                <dt>{{ __('verify.field_completed_on') }}</dt>
                <dd>{{ $view->completedOn }}</dd>
            </div>
            <div class="field">
                <dt>{{ __('verify.field_issued_at') }}</dt>
                <dd>{{ $view->issuedAt }}</dd>
            </div>
            <div class="field">
                <dt>{{ __('verify.field_confirmation') }}</dt>
                <dd>{{ $view->centreName }}</dd>
            </div>
        </dl>

        <p class="actions">
            <a href="{{ route('verify.certificates.form') }}">{{ __('verify.verify_another') }}</a>
        </p>
    </main>
</body>
</html>
