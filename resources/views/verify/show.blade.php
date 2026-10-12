<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('verify.page_title') }}</title>
    <style>
        :root {
            color-scheme: dark;
            --cream: #faf6ec;
            --gold: #e0c179;
            --muted: #c2cbd5;
            --line: rgba(250, 246, 236, .18);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-block-size: 100vh;
            background: radial-gradient(ellipse at 25% 20%, rgba(201, 164, 76, .12), transparent 55%), #0d2745;
            color: var(--cream);
            font-family: system-ui, sans-serif;
            line-height: 1.65;
        }

        a { color: inherit; text-underline-offset: .25em; }
        a:hover { color: var(--gold); }
        :focus-visible { outline: 3px solid var(--gold); outline-offset: 4px; }
        header {
            max-inline-size: 75rem;
            margin-inline: auto;
            padding-block: 1.5rem;
            padding-inline: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border-block-end: 1px solid var(--line);
        }

        .brand { font-family: Georgia, serif; font-size: 1.375rem; text-decoration: none; }
        .back-link { color: var(--muted); font-size: .875rem; }
        main {
            max-inline-size: 75rem;
            margin-inline: auto;
            padding-block: clamp(2.5rem, 7vw, 5.5rem);
            padding-inline: 1.5rem;
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
            align-items: start;
            gap: clamp(2rem, 5vw, 4rem);
        }

        .eyebrow {
            margin-block: 0 1rem;
            color: var(--gold);
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-family: Georgia, serif;
            font-size: clamp(2.25rem, 4vw, 3rem);
            font-weight: 400;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .intro { max-inline-size: 46ch; margin-block: 1.25rem 0; color: var(--muted); }
        .privacy {
            max-inline-size: 50ch;
            margin-block: 2rem 0;
            padding-block-start: 1.25rem;
            border-block-start: 1px solid var(--line);
            color: var(--muted);
            font-size: .8125rem;
        }

        .panel {
            min-inline-size: 0;
            padding: clamp(1.25rem, 3vw, 1.75rem);
            border: 1px solid var(--line);
            border-radius: 1.25rem;
            background: rgba(250, 246, 236, .05);
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-block-size: 3.25rem;
            padding-block: .75rem;
            padding-inline: 1.25rem;
            border: 1px solid transparent;
            border-radius: .75rem;
            background: #c9a44c;
            color: #14181f;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover { background: var(--gold); color: #14181f; }
        .actions { margin-block: 1.25rem 0; }
        @media (max-width: 48rem) {
            main { grid-template-columns: minmax(0, 1fr); }
        }
        .result {
            --status-color: var(--cream);
            --status-fill: rgba(250, 246, 236, .06);
            --status-line: rgba(250, 246, 236, .25);
            padding: 1.25rem;
            border: 1px solid var(--status-line);
            border-radius: 1rem;
            background: var(--status-fill);
        }

        .result-header { display: flex; align-items: start; gap: .75rem; }
        .status-mark { flex: none; inline-size: 1.75rem; block-size: 1.75rem; color: var(--status-color); }
        h2 { margin: 0; color: var(--status-color); font-size: 1rem; line-height: 1.5; }
        .status-message { margin-block: .375rem 0; color: var(--muted); font-size: .875rem; }
        .result--valid {
            --status-color: #7bd8a0;
            --status-fill: rgba(74, 187, 120, .12);
            --status-line: rgba(123, 216, 160, .35);
        }

        .result--replaced {
            --status-color: #e0c179;
            --status-fill: rgba(201, 164, 76, .12);
            --status-line: rgba(224, 193, 121, .35);
        }

        .result--revoked {
            --status-color: #f0908f;
            --status-fill: rgba(224, 102, 102, .12);
            --status-line: rgba(240, 144, 143, .35);
        }

        dl { margin-block: 1.25rem 0; border-block-start: 1px solid var(--line); }
        .field {
            display: grid;
            grid-template-columns: minmax(6.5rem, .65fr) minmax(0, 1.35fr);
            gap: .5rem 1rem;
            padding-block-start: 1rem;
        }

        dt { color: var(--muted); font-size: .8125rem; text-align: start; }
        dd { margin: 0; font-size: .875rem; font-weight: 600; text-align: start; overflow-wrap: anywhere; }
        @media (max-width: 30rem) {
            .field { grid-template-columns: minmax(0, 1fr); gap: .25rem; }
        }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        <a class="back-link" href="{{ url('/') }}">{{ __('verify.back_to_site') }}</a>
    </header>
    <main>
        <div>
            <p class="eyebrow">{{ __('verify.eyebrow') }}</p>
            <h1>{{ __('verify.show_heading') }}</h1>
            <p class="intro">{{ __('verify.result_intro') }}</p>
            <p class="privacy">{{ __('verify.privacy_note') }}</p>
        </div>
        <div class="panel">
            <section class="result result--{{ $view->status->value }}" aria-labelledby="result-heading">
                <div class="result-header">
                    <svg class="status-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke-width="1.5" />
                        @if ($view->status === \App\Domain\Enrollment\Enums\CertificateStatus::Valid)
                            <path d="m7 12 3 3 7-7" />
                        @elseif ($view->status === \App\Domain\Enrollment\Enums\CertificateStatus::Replaced)
                            <path d="M17 8a6 6 0 1 0 1 5M17 4v5h-5" />
                        @else
                            <path d="m8 8 8 8m0-8-8 8" />
                        @endif
                    </svg>
                    <div>
                        <h2 id="result-heading">{{ $view->status->label() }}</h2>
                        <p class="status-message">{{ __($statusMessageKey, $statusMessageReplacements) }}</p>
                    </div>
                </div>
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
            </section>
            <p class="actions">
                <a class="button" href="{{ route('verify.certificates.form') }}">{{ __('verify.verify_another') }}</a>
            </p>
        </div>
    </main>
</body>
</html>
