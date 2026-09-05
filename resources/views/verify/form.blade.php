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
         * surface (design section 7.4). Logical properties only, per
         * docs/ENGINEERING.md: margin-inline / padding-inline / text-align:
         * start, never a physical left/right so the page is correct the day
         * phase 4 renders it with dir="rtl".
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
            max-width: 28rem;
            background: #ffffff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.75rem;
        }

        h1 {
            margin-block: 0 0.5rem;
            font-size: 1.25rem;
        }

        p {
            margin-block: 0 1.25rem;
            color: #4b5563;
        }

        label {
            display: block;
            margin-block-end: 0.375rem;
            font-weight: 600;
        }

        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            padding-block: 0.5rem;
            padding-inline: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 1rem;
            text-align: start;
        }

        button {
            margin-block-start: 1rem;
            width: 100%;
            padding-block: 0.625rem;
            border: none;
            border-radius: 0.375rem;
            background: #111827;
            color: #ffffff;
            font-size: 1rem;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('verify.form_heading') }}</h1>
        <p>{{ __('verify.form_intro') }}</p>

        <form method="POST" action="{{ route('verify.certificates.submit') }}">
            @csrf

            <label for="reference">{{ __('verify.reference_label') }}</label>
            <input
                type="text"
                id="reference"
                name="reference"
                placeholder="{{ __('verify.reference_placeholder') }}"
                autocomplete="off"
                autocapitalize="characters"
                required
            >

            <button type="submit">{{ __('verify.submit_button') }}</button>
        </form>
    </main>
</body>
</html>
