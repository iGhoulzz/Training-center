@extends('layouts.public')

{{--
    One article: the navy header with its topic, issue date, title and authors, the
    filing record with the download count and the download link, the description as the
    lead paragraph, and up to three other published articles in the same topic.

    NOTHING HERE IS A MODEL. $article and every entry of $related are PublicArticle
    projections. The page shows no file size, no file name and no storage detail:
    the only thing that leads to the bytes is the download route's link, and that
    route decides again, on every request, whether the article is still published.
    The count is called approximate, because it is rate-limited and not
    deduplicated per reader.

    THE SECOND PARAGRAPH IS ALWAYS THE FALLBACK. The design shows an article's further
    paragraphs when it has them; this system stores a description and the PDF, never the
    paper's text, so the only thing to say after the description is where the full text
    is: in the PDF above.

    Every link goes through PublicRoute. No PHP block in this template, for the
    reason the index gives.
--}}
@use('App\Support\PublicRoute')
@use('Carbon\CarbonImmutable')

@section('title')
    {{ __('public.page_title', ['page' => $article->title, 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <x-public.page-header
        size="narrow"
        title-size="article"
        :back-href="PublicRoute::url('public.publications.index')"
        :back-label="__('publications.public.back')"
        :title="$article->title"
    >
        <x-slot:kicker>
            <x-public.article-meta :topic="$article->topic" :issued-on="$article->issuedOn" tone="dark" class="mt-5.5 gap-x-2.75 gap-y-1" />
        </x-slot:kicker>

        <p class="mt-4 text-sm text-cream/66">{{ $article->authors }}</p>
    </x-public.page-header>

    <x-public.container size="narrow" class="pt-11 pb-20">
        <x-public.card as="section" variant="box" aria-labelledby="article-download-heading" class="flex flex-wrap items-center gap-4 p-5">
            <x-public.pdf-thumb size="sm" />

            <div class="min-w-45 flex-1">
                <h2 id="article-download-heading" class="text-ui-lg font-semibold text-navy">{{ __('publications.public.original_filing') }}</h2>
                <p class="mt-0.5 text-caption text-meta">{{ trans_choice('publications.public.downloads', $article->downloadCount) }} · {{ __('publications.public.downloads_note') }} · {{ __('publications.public.uploaded_by') }}</p>
            </div>

            <x-public.button :href="PublicRoute::url('public.publications.download', ['slug' => $article->slug])" variant="control" size="control-lg" class="flex-none">
                <x-public.icon name="download" :size="14" :stroke-width="2.2" linejoin="miter" class="text-gold" />
                {{ __('publications.public.download_pdf') }}
            </x-public.button>
        </x-public.card>

        <p class="mt-8.5 text-article-lead whitespace-pre-line text-pretty text-ink-soft">{{ $article->description }}</p>
        <p class="mt-5 text-base leading-[1.85] text-pretty text-body">{{ __('publications.public.full_text_note') }}</p>

        @if ($related !== [])
            <section aria-labelledby="article-more-heading" class="mt-10 border-t border-navy/10 pt-7">
                <h2 id="article-more-heading" class="mb-4 text-eyebrow tracking-[0.12em] text-gold-dark uppercase">{{ __('publications.public.more_in', ['topic' => $article->topic]) }}</h2>

                <ul class="flex flex-col gap-2.5">
                    @foreach ($related as $other)
                        <li>
                            <a
                                href="{{ PublicRoute::url('public.publications.show', ['slug' => $other->slug]) }}"
                                class="flex items-center gap-3.5 rounded-item border border-navy/10 bg-card px-4.5 py-4 hover:border-gold/55"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block font-display text-related-title text-navy">{{ $other->title }}</span>
                                    <span class="mt-1 block text-small text-meta">{{ CarbonImmutable::parse($other->issuedOn)->translatedFormat(__('publications.public.date_format')) }}</span>
                                </span>
                                <x-public.icon name="arrow-right" :size="16" class="text-gold-dark rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </x-public.container>
@endsection
