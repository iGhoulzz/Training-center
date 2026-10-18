{{--
    The brand lockup: the centre's seal beside its name, as the design draws it in
    the header and in the footer. Every value (name, short name, tagline, Arabic
    name, logo path) comes from config/public_site.php, so it is changed in one
    place and no view holds the centre's name.

    PROPS. `variant` is `header` (the default: a 46px seal, the short name in the
    display face over the tagline, on the cream bar) or `footer` (a 44px seal, the
    short name in cream over the Arabic name, on navy).

    ACCESSIBILITY. The seal is decoration, so its alt text is empty. The visible
    short name and tagline are hidden from assistive technology, and a visually
    hidden span carries the full name, so a link around the header lockup is
    announced as the centre's name and the short name is not read letter by letter.
    The hidden name is in the page's language (the Arabic name on an Arabic page), and
    the visible short name and tagline are Latin whatever the page is, so each carries
    lang="en": that is also what keeps the Arabic page's display-heading rule (the
    stylesheet's `.font-display:lang(ar)`) from putting the Latin lockup in the Arabic face.
    The Arabic name is a different language and direction, so it carries
    lang="ar" and dir="rtl"; it sits in an element that keeps the page's direction,
    which is what lets its line start where the short name above it does.

    The lockup is spans only, so it is valid inside the header's link as it is. It
    takes no caller classes: the two places differ only by `variant`.
--}}
@props(['variant' => 'header'])

@php
    // The centre's full name in the page's language, for the hidden accessible name.
    $brandName = config('public_site.brand.'.(str_starts_with(app()->getLocale(), 'ar') ? 'name_ar' : 'name'));
@endphp

@if ($variant === 'footer')
    <span class="flex items-center gap-3">
        <img
            src="{{ asset(config('public_site.brand.logo')) }}"
            alt=""
            width="44"
            height="44"
            loading="lazy"
            decoding="async"
            class="size-11 shrink-0 rounded-pill border-[1.5px] border-gold/50 object-cover object-center"
        >
        <span class="flex flex-col">
            <span aria-hidden="true" lang="en" class="font-display text-brand-sm text-cream">{{ config('public_site.brand.short_name') }}</span>
            <span class="sr-only">{{ $brandName }}</span>
            <span class="text-label font-semibold tracking-normal text-gold">
                <span lang="ar" dir="rtl">{{ config('public_site.brand.name_ar') }}</span>
            </span>
        </span>
    </span>
@else
    <span class="flex items-center gap-3">
        <img
            src="{{ asset(config('public_site.brand.logo')) }}"
            alt=""
            width="46"
            height="46"
            decoding="async"
            class="size-11.5 shrink-0 rounded-pill border-[1.5px] border-gold/55 object-cover object-center"
        >
        <span aria-hidden="true" class="flex flex-col leading-[1.15]">
            <span lang="en" class="font-display text-brand text-navy">{{ config('public_site.brand.short_name') }}</span>
            <span lang="en" class="text-tagline text-subtitle uppercase">{{ config('public_site.brand.tagline') }}</span>
        </span>
        <span class="sr-only">{{ $brandName }}</span>
    </span>
@endif
