{{--
    An article's topic and issue date, on one line: the badge and the date the home
    teaser, the library card and the article page each open with. Shared, so the three
    cannot disagree about how a topic is shown, how a date is written, or what the
    date is called.

    PROPS. `topic` is the article's topic and `issuedOn` its issue date as Y-m-d, both
    taken from a PublicArticle, the closed projection the public pages render, never a
    model. `tone` is `light` (the white and cream cards: a square-cornered badge and a
    12px grey date) or `dark` (the article's navy header: a pill badge and a 13px cream
    date at 60%, which is 4.73:1 over the glow). `issuedLabel` is whether the date reads
    "Issued 12 Feb 2026" (the teaser and the article) or the bare "12 Feb 2026" (the
    library card, whose line has no room for the word).

    The caller adds the gap between the badge and the date, and its own spacing, through
    $attributes: the design measures the gap differently in each of the three places.
    The element carries `data-article-meta`, which is how a test finds it.

    The date goes through two translation keys because its wording and its format are
    localisable. Nothing here queries.
--}}
@props(['topic', 'issuedOn', 'tone' => 'light', 'issuedLabel' => true])

@use('Carbon\CarbonImmutable')

@php
    $date = CarbonImmutable::parse($issuedOn)->translatedFormat(__('publications.public.date_format'));
@endphp

<p data-article-meta {{ $attributes->class(['flex flex-wrap items-center']) }}>
    <x-public.badge :tone="$tone">{{ $topic }}</x-public.badge>
    <time datetime="{{ $issuedOn }}" @class(['text-small text-meta' => $tone === 'light', 'text-compact text-cream/60' => $tone === 'dark'])>{{ $issuedLabel ? __('publications.public.issued', ['date' => $date]) : $date }}</time>
</p>
