{{--
    THE LANGUAGE SWITCH (P4-T18a): a two-segment pill, `EN | ع`, with a thumb under the
    current language. One component for three surfaces, because the design draws none
    and the three must agree.

    PROPS.
    - `variant`: `public` (the default; the navy top bar of the public site),
      `verifier` (the certificate verifier's header) or `admin` (the panel's topbar).
    - `to`: for `public` and `verifier`, the route (by its English name) the link goes
      to INSTEAD OF the page the visitor is on. Only the verifier's uniform miss sets it,
      to `verify.certificates.form`; see below.

    THE PILL IS `dir="ltr"` ITSELF, in both page directions. EN is always the left half
    and ع the right, so a change of language moves the thumb from one side to the other
    instead of leaving it where it was. Each label carries its own `lang`, so a screen
    reader says it in its own language whatever the page is in.

    PUBLIC AND VERIFIER: A LINK, NOT A BUTTON. Switching language is a navigation to
    the same page in the other language, so the target half is `<a href hreflang lang>`.
    The current half is a span: visible, `aria-current`, and not a link. The link's
    accessible name is the TARGET language's own name (`العربية` on the English page), read
    through that language's catalogue, so a person who cannot read the page can still
    find the way out of it. The href is PublicRoute::counterpart(): the same route, the
    same parameters and the same query string in the other locale.

    ON THE VERIFIER'S MISS THE LINK GOES TO THE OTHER LANGUAGE'S FORM, never to the page
    the visitor is on. A miss is one page for four different requests (a malformed GET,
    an unknown GET, an empty POST, a malformed POST), and its bytes must be identical
    across them (design section 7.3). A link to the current page would carry the
    submitted reference into those bytes, and make the four pages differ from one
    another. `to` is how the miss says so.

    THE MOTION HAS NO SCRIPT ON THE PUBLIC SITE AND THE VERIFIER. Hover nudges the thumb
    a few pixels toward the other half. Navigating between the two languages is a
    cross-document view transition (`@view-transition` in public.css and in the
    verifier's inline CSS), and the thumb, with the two labels, carries a
    `view-transition-name`, so the browser slides it across as the page changes
    direction. A browser without view transitions simply navigates. The CSS is
    resources/css/public.css (`.locale-switch`) and verify/partials/locale.blade.php.

    ADMIN: A FORM. In the panel the choice is stored on the signed-in user (users.locale)
    through UpdateOwnLocaleAction, so it is a POST with the CSRF token, and the page
    behind it redirects back. Alpine, which Filament ships, slides the thumb on click
    before the form submits; without it the form still works. It is drawn from the
    panel's own palettes (gray-800 and primary-500) as utilities, which the admin theme
    compiles from this file. The panel renders it only while the flag
    `public_site.admin_locale_switch` is on (AdminPanelProvider), until the admin has its
    Arabic and its direction.

    CONTRAST, measured with WCAG's formula: the public pill's inactive label is cream at
    72% over the pill's 6% cream fill on navy, 6.94:1; the current label is ink on gold,
    7.54:1. The admin's inactive label is gray-400 on gray-800, 5.78:1, and its current
    one on-primary on primary-500, 8.59:1. LocaleSwitchTest recomputes each from the CSS.
--}}
@use('App\Http\Middleware\SetLocale')
@use('App\Support\PublicRoute')

@props(['variant' => 'public', 'to' => null])

@php
    $current = in_array(app()->getLocale(), SetLocale::SUPPORTED, true) ? app()->getLocale() : PublicRoute::ENGLISH_LOCALE;
@endphp

@if ($variant === 'admin')
    {{-- The pill's classes are written out in full: Tailwind compiles only the class names it can read. --}}
    <form
        method="post"
        action="{{ route('account.locale.update') }}"
        x-data="{ moved: false }"
        {{ $attributes }}
    >
        @csrf

        <div
            role="group"
            aria-label="{{ __('public.locale_switch.label') }}"
            dir="ltr"
            data-locale-switch
            data-current="{{ $current }}"
            class="relative isolate inline-flex rounded-full bg-gray-800 text-xs font-semibold"
        >
            {{-- Placed by the server (start-*) and moved by Alpine (translate), so the two never fight over one property. --}}
            <span
                aria-hidden="true"
                class="{{ $current === 'ar' ? 'start-[calc(50%+0.125rem)]' : 'start-0.5' }} absolute inset-y-0.5 w-[calc(50%-0.25rem)] rounded-full bg-primary-500 motion-safe:transition-transform motion-safe:duration-200"
                x-bind:class="moved ? '{{ $current === 'ar' ? '-translate-x-[calc(100%+0.25rem)]' : 'translate-x-[calc(100%+0.25rem)]' }}' : ''"
            ></span>

            @foreach (SetLocale::SUPPORTED as $locale)
                @if ($locale === $current)
                    <span
                        lang="{{ $locale }}"
                        aria-current="true"
                        class="relative z-10 flex min-h-8 min-w-10 flex-1 items-center justify-center px-3 text-on-primary"
                    >{{ __('public.locale_switch.short', [], $locale) }}</span>
                @else
                    <button
                        type="submit"
                        name="locale"
                        value="{{ $locale }}"
                        lang="{{ $locale }}"
                        aria-label="{{ __('public.locale_switch.name', [], $locale) }}"
                        x-on:click.prevent="moved = true; setTimeout(() => $el.form.requestSubmit($el), window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220)"
                        class="relative z-10 flex min-h-8 min-w-10 flex-1 cursor-pointer items-center justify-center px-3 text-gray-400 hover:text-gray-50 focus-visible:outline-primary-400"
                    >{{ __('public.locale_switch.short', [], $locale) }}</button>
                @endif
            @endforeach
        </div>
    </form>
@else
    <div
        role="group"
        aria-label="{{ __('public.locale_switch.label') }}"
        dir="ltr"
        data-locale-switch
        data-current="{{ $current }}"
        class="locale-switch"
    >
        <span class="locale-switch__thumb" aria-hidden="true"></span>

        @foreach (SetLocale::SUPPORTED as $locale)
            @if ($locale === $current)
                <span lang="{{ $locale }}" aria-current="true" class="locale-switch__option locale-switch__option--{{ $locale }} locale-switch__option--current">{{ __('public.locale_switch.short', [], $locale) }}</span>
            @else
                <a
                    href="{{ $to === null ? PublicRoute::counterpart($locale) : PublicRoute::urlIn($locale, $to) }}"
                    hreflang="{{ $locale }}"
                    lang="{{ $locale }}"
                    aria-label="{{ __('public.locale_switch.name', [], $locale) }}"
                    class="locale-switch__option locale-switch__option--{{ $locale }} tap-target"
                >{{ __('public.locale_switch.short', [], $locale) }}</a>
            @endif
        @endforeach
    </div>
@endif
