{{--
    THE VERIFIER'S LANGUAGE AND DIRECTION CSS (P4-T18a), included INSIDE the one <style>
    of each of the three pages (form, show, not-found), so there is still exactly one
    inline stylesheet per page, no stylesheet of the site's, no script and no third-party
    origin. It is a partial and not three copies so that the three pages cannot disagree.
    It holds no markup and prints no variable: it is static CSS, written with the custom
    properties each page declares at the top of its own <style> (--navy, --gold,
    --gold-light, --cream and --ink).

    It restates three things the public site's stylesheet does for its pages
    (resources/css/public.css, which these pages do not load):
    - the language switch's pill, in the same values: a gold hairline at 30%, a cream fill
      at 6%, a gold thumb with ink text, the other half in cream at 72%. Measured over
      the worst the page's glow can put behind it (navy plus the glow at its full .18),
      that label is 5.4:1; LocaleSwitchTest recomputes it. See
      resources/views/components/locale-switch.blade.php for the markup and public.css
      for the reasoning about the thumb and the motion;
    - the cross-document view transition, so the thumb slides when the language changes
      (both pages must opt in, and these are the pages the verifier's switch links
      between), inside `no-preference` so reduced motion gets none;
    - Arabic type: no letter-spacing on Arabic (it pulls cursive letters apart) and the
      UI face, not the display face, for an Arabic heading, which Instrument Serif cannot
      draw.

    The glow behind the page is positioned from the left (20%), where the intro sits in
    English. In Arabic the intro is at the right, so the glow is mirrored to 80%.
--}}
        .bar-end { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.5rem; }
        .locale-cell { display: flex; align-items: center; min-block-size: 44px; }
        .locale-switch {
            --switch-line: rgb(201 164 76 / .3);
            --switch-fill: rgb(250 246 236 / .06);
            --text-switch: rgb(250 246 236 / .72);
            --locale-nudge: 3px;

            position: relative;
            isolation: isolate;
            display: inline-flex;
            align-items: stretch;
            border: 1px solid var(--switch-line);
            border-radius: 999px;
            background: var(--switch-fill);
            font-size: .78125rem;
            font-weight: 600;
            line-height: 1.6;
        }

        .locale-switch[data-current="ar"] { --locale-nudge: -3px; }
        .locale-switch__thumb {
            position: absolute;
            inset-block: 2px;
            inset-inline-start: 2px;
            inline-size: calc(50% - 4px);
            border-radius: 999px;
            background: var(--gold);
            transition: translate .2s ease;
            view-transition-name: locale-thumb;
        }

        .locale-switch[data-current="ar"] .locale-switch__thumb { inset-inline-start: calc(50% + 2px); }
        .locale-switch__option {
            position: relative;
            z-index: 1;
            display: flex;
            flex: 1 1 0;
            align-items: center;
            justify-content: center;
            min-inline-size: 2.5rem;
            padding-block: .25rem;
            padding-inline: .75rem;
            border-radius: 999px;
            color: var(--text-switch);
            transition: color .2s ease;
        }

        .locale-switch__option--en { view-transition-name: locale-en; }
        .locale-switch__option--ar { view-transition-name: locale-ar; }
        a.locale-switch__option:hover { color: var(--cream); }
        .locale-switch__option--current { color: var(--ink); }
        .locale-switch__option:focus-visible { outline: 2px solid var(--gold-light); outline-offset: 1px; }
        a.locale-switch__option::after {
            content: "";
            position: absolute;
            inset-block: min(0px, calc(50% - 22px));
            inset-inline: min(0px, calc(50% - 22px));
        }

        .locale-switch:has(a:is(:hover, :focus-visible)) .locale-switch__thumb { translate: var(--locale-nudge) 0; }
        @media (prefers-reduced-motion: reduce) {
            .locale-switch__thumb, .locale-switch__option { transition: none; }
            .locale-switch:has(a:is(:hover, :focus-visible)) .locale-switch__thumb { translate: none; }
        }

        @media (forced-colors: active) {
            .locale-switch__thumb { background-color: Highlight; }
            .locale-switch__option--current { color: HighlightText; }
        }

        @media (prefers-reduced-motion: no-preference) {
            @view-transition { navigation: auto; }
        }

        ::view-transition-group(root) { animation-duration: .2s; }
        ::view-transition-group(locale-thumb),
        ::view-transition-group(locale-en),
        ::view-transition-group(locale-ar) { animation-duration: .35s; animation-timing-function: cubic-bezier(.22, 1, .36, 1); }

        html[dir="rtl"] body { background: radial-gradient(circle at 80% 30%, var(--glow), transparent 45%), var(--navy); }
        *:lang(ar):not(input) { letter-spacing: normal; }
        h1:lang(ar) { font-family: var(--font-ui); font-weight: 600; line-height: 1.4; }
