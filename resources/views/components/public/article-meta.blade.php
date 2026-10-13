{{--
    An article's topic and issue date, on one line: the badge and the date the
    library card and the article page both open with. Shared, so the two cannot
    disagree about how a topic is shown, how a date is written, or what the date is
    called.

    PROPS. `topic` is the article's topic and `issuedOn` its issue date as Y-m-d,
    both taken from a PublicArticle, the closed projection the public pages render,
    never a model. There is no attribute bag: a page that wants space around it
    wraps the component, so the fragment is byte-for-byte the same wherever it is
    used, which PublicationsIndexTest asserts.

    The date goes through two translation keys because its wording and its format
    are localisable. Nothing here queries, and there is no PHP block in this
    template: LocalizationTest reads the body of one as untranslated prose.
--}}
@props(['topic', 'issuedOn'])

@use('Carbon\CarbonImmutable')

<p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
    <span class="rounded-full bg-cream-100 px-3 py-1 font-semibold text-navy-800">{{ $topic }}</span>
    <time datetime="{{ $issuedOn }}" class="text-navy-700">{{ __('publications.public.issued', ['date' => CarbonImmutable::parse($issuedOn)->translatedFormat(__('publications.public.date_format'))]) }}</time>
</p>
