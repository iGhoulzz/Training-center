<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('verify.page_title') }}</title>
    <style>
        /*
         * NO FORM. NO CSRF TOKEN. NO ECHOED INPUT. NO ERROR BAG.
         *
         * That is what makes the byte-identical assertion across a malformed
         * GET, an unknown GET, an empty POST and a malformed POST possible at
         * all — a CSRF token differs on every render, so a page carrying one
         * could never compare equal to itself twice, let alone across the
         * four cases VerifyCertificateController::notFound() serves this to.
         * Everything below is a fixed lang key; nothing here reads $_GET,
         * $_POST, the route parameter, or session()->getErrorBag().
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
            text-align: start;
        }

        h1 {
            margin-block: 0 0.5rem;
            font-size: 1.25rem;
        }

        p {
            margin-block: 0;
            color: #4b5563;
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
        <h1>{{ __('verify.not_found_heading') }}</h1>
        <p>{{ __('verify.not_found_body') }}</p>

        <p class="actions">
            <a href="{{ route('verify.certificates.form') }}">{{ __('verify.verify_another') }}</a>
        </p>
    </main>
</body>
</html>
