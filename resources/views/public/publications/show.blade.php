@extends('layouts.public')

{{--
    One article: its topic, issue date, title and authors, a box with the download
    count and the download link, the description, and up to three other published
    articles in the same topic.

    NOTHING HERE IS A MODEL. $article and every entry of $related are PublicArticle
    projections. The page shows no file size, no file name and no storage detail:
    the only thing that leads to the bytes is the download route's link, and that
    route decides again, on every request, whether the article is still published.
    The count is called approximate, because it is rate-limited and not
    deduplicated per reader.

    Every link goes through PublicRoute. No PHP block in this template, for the
    reason the index gives.
--}}
@use('App\Support\PublicRoute')
@use('Carbon\CarbonImmutable')

@section('title')
    {{ __('public.page_title', ['page' => $article->title, 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <p><a href="{{ PublicRoute::url('public.publications.index') }}" class="text-sm font-medium text-navy hover:underline">{{ __('publications.public.back') }}</a></p>

        <div class="mt-6">
            <x-public.article-meta :topic="$article->topic" :issued-on="$article->issuedOn" />
        </div>

        <h1 class="mt-4 font-display text-4xl text-navy">{{ $article->title }}</h1>
        <p class="mt-3 text-meta">{{ $article->authors }}</p>

        <section aria-labelledby="article-download-heading" class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-card border border-navy/9 bg-card p-6">
            <div>
                <h2 id="article-download-heading" class="font-semibold text-navy">{{ __('publications.public.download_heading') }}</h2>
                <p class="mt-1 text-sm text-meta">{{ trans_choice('publications.public.downloads', $article->downloadCount) }}</p>
                <p class="text-sm text-meta">{{ __('publications.public.downloads_note') }}</p>
            </div>

            <a
                href="{{ PublicRoute::url('public.publications.download', ['slug' => $article->slug]) }}"
                class="inline-flex items-center rounded-pill bg-navy px-6 py-3 font-medium text-cream hover:bg-navy-hover"
            >{{ __('publications.public.download_pdf') }}</a>
        </section>

        <p class="mt-8 whitespace-pre-line text-lg text-body">{{ $article->description }}</p>

        @if ($related !== [])
            <section aria-labelledby="article-more-heading" class="mt-12 border-t border-navy/9 pt-8">
                <h2 id="article-more-heading" class="text-sm font-semibold uppercase tracking-wider text-gold-dark">{{ __('publications.public.more_in', ['topic' => $article->topic]) }}</h2>

                <ul class="mt-4 flex flex-col gap-3">
                    @foreach ($related as $other)
                        <li>
                            <a
                                href="{{ PublicRoute::url('public.publications.show', ['slug' => $other->slug]) }}"
                                class="block rounded-card border border-navy/9 bg-card p-4 hover:bg-cream"
                            >
                                <span class="block font-display text-lg text-navy">{{ $other->title }}</span>
                                <span class="block text-sm text-meta">{{ CarbonImmutable::parse($other->issuedOn)->translatedFormat(__('publications.public.date_format')) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
