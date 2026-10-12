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
    {{ __('public.page_title', ['page' => $article->title, 'name' => config('app.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <p><a href="{{ PublicRoute::url('public.publications.index') }}" class="text-sm font-medium text-navy-700 hover:underline">{{ __('publications.public.back') }}</a></p>

        <p class="mt-6 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="rounded-full bg-cream-100 px-3 py-1 font-semibold text-navy-800">{{ $article->topic }}</span>
            <time datetime="{{ $article->issuedOn }}" class="text-navy-700">{{ __('publications.public.issued', ['date' => CarbonImmutable::parse($article->issuedOn)->translatedFormat(__('publications.public.date_format'))]) }}</time>
        </p>

        <h1 class="mt-4 font-serif text-4xl font-semibold">{{ $article->title }}</h1>
        <p class="mt-3 text-navy-700">{{ $article->authors }}</p>

        <section aria-labelledby="article-download-heading" class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-cream-200 bg-white p-6">
            <div>
                <h2 id="article-download-heading" class="font-semibold text-navy-900">{{ __('publications.public.download_heading') }}</h2>
                <p class="mt-1 text-sm text-navy-700">{{ trans_choice('publications.public.downloads', $article->downloadCount) }}</p>
                <p class="text-sm text-navy-700">{{ __('publications.public.downloads_note') }}</p>
            </div>

            <a
                href="{{ PublicRoute::url('public.publications.download', ['slug' => $article->slug]) }}"
                class="inline-flex items-center rounded-full bg-navy-900 px-6 py-3 font-medium text-cream-50 hover:bg-navy-800"
            >{{ __('publications.public.download_pdf') }}</a>
        </section>

        <p class="mt-8 whitespace-pre-line text-lg text-navy-800">{{ $article->description }}</p>

        @if ($related !== [])
            <section aria-labelledby="article-more-heading" class="mt-12 border-t border-cream-200 pt-8">
                <h2 id="article-more-heading" class="text-sm font-semibold uppercase tracking-wider text-navy-700">{{ __('publications.public.more_in', ['topic' => $article->topic]) }}</h2>

                <ul class="mt-4 flex flex-col gap-3">
                    @foreach ($related as $other)
                        <li>
                            <a
                                href="{{ PublicRoute::url('public.publications.show', ['slug' => $other->slug]) }}"
                                class="block rounded-xl border border-cream-200 bg-white p-4 hover:bg-cream-100"
                            >
                                <span class="block font-serif text-lg font-semibold text-navy-900">{{ $other->title }}</span>
                                <span class="block text-sm text-navy-700">{{ CarbonImmutable::parse($other->issuedOn)->translatedFormat(__('publications.public.date_format')) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
