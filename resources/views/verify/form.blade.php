{{--
    STANDALONE: ONE INLINE <style>, NO SCRIPT, NO THIRD-PARTY ORIGIN. The page loads
    no stylesheet of the site's (design section 7.4), so the public design system's
    values are restated below as custom properties, from the design's CERTIFICATE
    VERIFIER section. VerificationDisclosureTest enumerates every URL on the page.

    THE FONTS ARE LOADED ONLY WHEN THEY HAVE BEEN BUILT, and the guard is the public
    layout's, computed the same way and ahead of the markup. CI runs the suite before
    it builds assets, and Vite::fonts() throws when a fonts manifest exists but lists
    none of the families asked for, as a leftover one from an older build does.
    Without a build the page is complete and falls back to system fonts. With one,
    every tag it emits is on this origin.

    Vite::flush() comes first because Vite remembers, for the life of the process, which
    font preloads it has already emitted and leaves them out of the next render. These
    pages must be the same bytes every time (the uniform miss is compared byte for byte
    across requests), so each render starts from a clean slate.
--}}
@use('Illuminate\Support\Facades\Vite')

@php
    $stylesheetBuilt = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('verify.page_title') }}</title>
    @if ($stylesheetBuilt)
        @php(Vite::flush())
        {{ Vite::fonts(['instrument-serif', 'ibm-plex-sans-arabic']) }}
    @endif
    <style>
        :root {
            color-scheme: dark;

            /* The brand: resources/css/public.css, restated. */
            --navy: #0d2745;
            --gold: #c9a44c;
            --gold-light: #e0c179;
            --cream: #faf6ec;
            --ink: #14181f;

            /* Type. */
            --font-display: 'Instrument Serif', Georgia, 'Times New Roman', Times, serif;
            --font-ui: 'IBM Plex Sans Arabic', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;

            /* Shape. */
            --radius-panel: 20px;
            --radius-card: 16px;
            --radius-control: 12px;

            /* Surfaces: the page's glow, the panel and the input. */
            --glow: rgb(201 164 76 / .18);
            --panel-fill: rgb(250 246 236 / .05);
            --line: rgb(250 246 236 / .14);
            --field-fill: rgb(13 39 69 / .5);
            --field-line: rgb(250 246 236 / .22);
            --mark-line: rgb(201 164 76 / .5);

            /*
             * Text: the cream at the opacities the design uses, each checked against
             * WCAG 4.5:1 over the navy page and its panel. The row label is the design's
             * .55 raised to .58 and the note its .45 raised to .57; the placeholder has
             * no value in the design.
             */
            --text-intro: rgb(250 246 236 / .74);
            --text-label: rgb(250 246 236 / .6);
            --text-sub: rgb(250 246 236 / .66);
            --text-row-label: rgb(250 246 236 / .58);
            --text-note: rgb(250 246 236 / .57);
            --text-placeholder: rgb(250 246 236 / .6);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-block-size: 100vh;
            min-block-size: 100dvh;
            background: radial-gradient(circle at 20% 30%, var(--glow), transparent 45%), var(--navy);
            color: var(--cream);
            font-family: var(--font-ui);
            font-size: 1rem;
            line-height: 1.6;
        }

        a { color: inherit; text-decoration: none; }
        a:hover { color: var(--gold-light); }
        :focus-visible { outline: 3px solid var(--gold-light); outline-offset: 3px; }
        .sr-only {
            position: absolute;
            inline-size: 1px;
            block-size: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
            border: 0;
        }

        .site-header { border-block-end: 1px solid var(--line); }
        .bar {
            max-inline-size: 75rem;
            margin-inline: auto;
            padding-block: .75rem;
            padding-inline: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: .5rem 1.5rem;
        }

        .brand { display: flex; align-items: center; gap: 12px; min-inline-size: 0; }
        .brand-mark {
            flex: none;
            inline-size: 44px;
            block-size: 44px;
            border: 1.5px solid var(--mark-line);
            border-radius: 999px;
            object-fit: cover;
        }

        .brand-name {
            display: block;
            font-family: var(--font-display);
            font-size: 1.1875rem;
            letter-spacing: .06em;
            color: var(--cream);
        }

        .brand:hover .brand-name { color: var(--gold-light); }
        .brand-name-ar { display: block; font-size: .71875rem; font-weight: 600; color: var(--gold); }
        .back-link {
            display: inline-flex;
            align-items: center;
            min-block-size: 44px;
            font-size: .875rem;
            color: var(--text-intro);
        }

        .back-link:hover { text-decoration: underline; text-underline-offset: .25em; }
        main {
            max-inline-size: 75rem;
            margin-inline: auto;
            padding-block: 3rem;
            padding-inline: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 52px;
        }

        .lede { flex: 1 1 340px; min-inline-size: 0; }
        .eyebrow {
            margin: 0;
            font-size: .78125rem;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--gold);
        }

        h1 {
            margin: 0;
            margin-block-start: .75rem;
            font-family: var(--font-display);
            font-size: clamp(1.75rem, 3.4vw, 2.5rem);
            font-weight: 400;
            line-height: 1.15;
            text-wrap: balance;
            overflow-wrap: anywhere;
        }

        .intro {
            margin: 0;
            margin-block-start: 1rem;
            max-inline-size: 480px;
            font-size: 1rem;
            line-height: 1.8;
            color: var(--text-intro);
            text-wrap: pretty;
        }

        .note {
            margin: 0;
            margin-block-start: 1.375rem;
            max-inline-size: 480px;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-note);
        }

        .panel {
            flex: 1 1 400px;
            min-inline-size: 0;
            padding: 26px;
            border: 1px solid var(--line);
            border-radius: var(--radius-panel);
            background: var(--panel-fill);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
        }

        label {
            display: block;
            margin-block-end: 10px;
            font-size: .78125rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text-label);
        }

        .input-row { display: flex; flex-wrap: wrap; gap: 10px; }
        input[type="text"] {
            flex: 1 1 190px;
            min-inline-size: 0;
            min-block-size: 44px;
            padding-block: 14px;
            padding-inline: 16px;
            border: 1px solid var(--field-line);
            border-radius: var(--radius-control);
            background: var(--field-fill);
            color: var(--cream);
            font-family: inherit;
            font-size: 1rem;
            letter-spacing: .03em;
            text-align: start;
            outline: none;
        }

        input[type="text"]:focus { border-color: var(--gold); }
        input[type="text"]:focus-visible { outline: 2px solid var(--gold-light); outline-offset: 2px; }
        input::placeholder { color: var(--text-placeholder); opacity: 1; }
        .button {
            display: inline-flex;
            flex: 1 1 100%;
            align-items: center;
            justify-content: center;
            min-block-size: 44px;
            padding-block: 14px;
            padding-inline: 24px;
            border: 0;
            border-radius: var(--radius-control);
            background: var(--gold);
            color: var(--ink);
            font-family: inherit;
            font-size: .875rem;
            font-weight: 600;
            line-height: normal;
            white-space: nowrap;
            cursor: pointer;
        }

        .button:hover { background: var(--gold-light); color: var(--ink); }
        .hint { margin: 0; margin-block-start: 10px; font-size: .78125rem; line-height: 1.6; color: var(--text-sub); }
        @media (min-width: 30rem) {
            .button { flex: none; }
        }
        @media (min-width: 48rem) {
            main { padding-block: 5rem; }
        }
        @media (min-width: 64rem) {
            .intro { font-size: .96875rem; }
            .note { font-size: .78125rem; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="bar">
            <a class="brand" href="{{ url('/') }}">
                <img class="brand-mark" src="{{ asset(config('public_site.brand.logo')) }}" alt="" width="44" height="44">
                <span>
                    <span class="brand-name" aria-hidden="true">{{ config('public_site.brand.short_name') }}</span>
                    <span class="sr-only">{{ config('public_site.brand.name') }}</span>
                    <span class="brand-name-ar"><span lang="ar" dir="rtl">{{ config('public_site.brand.name_ar') }}</span></span>
                </span>
            </a>
            <a class="back-link" href="{{ url('/') }}">{{ __('verify.back_to_site') }}</a>
        </div>
    </header>
    <main>
        <div class="lede">
            <p class="eyebrow">{{ __('verify.eyebrow') }}</p>
            <h1>{{ __('verify.form_heading') }}</h1>
            <p class="intro">{{ __('verify.form_intro') }}</p>
            <p class="note">{{ __('verify.privacy_note') }}</p>
        </div>
        <div class="panel">
            <form method="POST" action="{{ route('verify.certificates.submit') }}">
                @csrf

                <label for="reference">{{ __('verify.reference_label') }}</label>
                <div class="input-row">
                    <input
                        type="text"
                        id="reference"
                        name="reference"
                        placeholder="{{ __('verify.reference_placeholder') }}"
                        aria-describedby="reference-help"
                        autocomplete="off"
                        autocapitalize="characters"
                        required
                    >
                    <button class="button" type="submit">{{ __('verify.submit_button') }}</button>
                </div>
                <p class="hint" id="reference-help">{{ __('verify.reference_hint') }}</p>
            </form>
        </div>
    </main>
</body>
</html>
