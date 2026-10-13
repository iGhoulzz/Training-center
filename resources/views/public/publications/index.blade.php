@extends('layouts.public')

{{--
    The publications library: a search box, the topic chips, the sort links, the
    count line, one card per article and the page links. Which articles show, in
    what order and how many to a page is PublicationController's and
    PublicArticleCatalogue's business; this page only lays them out.

    NOTHING HERE IS A MODEL. $articles is a list of PublicArticle projections,
    $topics a list of [topic, count] pairs, and everything else a string, a number
    or null, including the previous and next page URLs.

    THE SEARCH BOX IS THE ONE FORM A PUBLIC PAGE MAY HOLD, and it is a read-only
    GET form: it writes q, topic and sort into the URL and nothing else, so it
    needs no session and no CSRF token (docs/ENGINEERING.md, "Frontend (phase 4)").
    The hidden inputs keep the topic and the sort across a new search.

    Every link, the form's action included, goes through PublicRoute. The links
    that keep the filters drop the empty ones (array_filter with strlen keeps a
    search for "0"), so an unfiltered library has a clean URL.

    NO PHP BLOCK IN THIS TEMPLATE: LocalizationTest reads the body of one as
    untranslated prose, so every value is computed inline.
--}}
@use('App\Domain\Publications\Queries\PublicArticleCatalogue')
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('publications.public.heading'), 'name' => config('app.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="font-serif text-4xl font-semibold">{{ __('publications.public.heading') }}</h1>
        <p class="mt-4 max-w-2xl text-lg text-navy-800">{{ __('publications.public.intro') }}</p>

        <form
            method="get"
            action="{{ PublicRoute::url('public.publications.index') }}"
            role="search"
            class="mt-8 flex flex-wrap items-end gap-3"
        >
            <div class="min-w-64 grow">
                <label for="publications-search" class="block text-sm font-medium text-navy-800">{{ __('publications.public.search_label') }}</label>
                <input
                    id="publications-search"
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="{{ __('publications.public.search_placeholder') }}"
                    class="mt-1 block w-full rounded-lg border border-navy-800/40 bg-white px-4 py-2 text-navy-900"
                >
            </div>

            @if ($topic !== '')
                <input type="hidden" name="topic" value="{{ $topic }}">
            @endif
            <input type="hidden" name="sort" value="{{ $sort }}">

            <button
                type="submit"
                class="rounded-full bg-navy-900 px-6 py-2 font-medium text-cream-50 hover:bg-navy-800"
            >{{ __('publications.public.search_submit') }}</button>
        </form>

        <nav aria-label="{{ __('publications.public.topics_label') }}" class="mt-6">
            <ul class="flex flex-wrap gap-2">
                <li>
                    <a
                        href="{{ PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'sort' => $sort], 'strlen')) }}"
                        @if ($topic === '') aria-current="true" @endif
                        class="inline-flex items-center gap-2 rounded-full border border-navy-800/40 px-4 py-1 text-sm font-medium hover:bg-cream-100 {{ $topic === '' ? 'bg-navy-900 text-cream-50 hover:bg-navy-900' : 'bg-white text-navy-900' }}"
                    >{{ __('publications.public.topic_all') }} <span>{{ array_sum(array_column($topics, 'count')) }}</span></a>
                </li>

                @foreach ($topics as $entry)
                    <li>
                        <a
                            href="{{ PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'topic' => $entry['topic'], 'sort' => $sort], 'strlen')) }}"
                            @if (mb_strtolower($topic) === mb_strtolower($entry['topic'])) aria-current="true" @endif
                            class="inline-flex items-center gap-2 rounded-full border border-navy-800/40 px-4 py-1 text-sm font-medium hover:bg-cream-100 {{ mb_strtolower($topic) === mb_strtolower($entry['topic']) ? 'bg-navy-900 text-cream-50 hover:bg-navy-900' : 'bg-white text-navy-900' }}"
                        >{{ $entry['topic'] }} <span>{{ $entry['count'] }}</span></a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
            <p class="text-sm text-navy-800">
                @if ($topic !== '' && $search !== '')
                    {{ trans_choice('publications.public.count_in_topic_matching', $total, ['topic' => $topic, 'search' => $search]) }}
                @elseif ($topic !== '')
                    {{ trans_choice('publications.public.count_in_topic', $total, ['topic' => $topic]) }}
                @elseif ($search !== '')
                    {{ trans_choice('publications.public.count_matching', $total, ['search' => $search]) }}
                @else
                    {{ trans_choice('publications.public.count', $total) }}
                @endif
            </p>

            <nav aria-label="{{ __('publications.public.sort_label') }}">
                <ul class="flex items-center gap-4 text-sm">
                    <li>
                        <a
                            href="{{ PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'topic' => $topic, 'sort' => PublicArticleCatalogue::SORT_NEWEST], 'strlen')) }}"
                            @if ($sort === PublicArticleCatalogue::SORT_NEWEST) aria-current="true" @endif
                            class="rounded-md py-1 hover:underline {{ $sort === PublicArticleCatalogue::SORT_NEWEST ? 'font-semibold text-navy-900 underline' : 'text-navy-700' }}"
                        >{{ __('publications.public.sort_newest') }}</a>
                    </li>
                    <li>
                        <a
                            href="{{ PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'topic' => $topic, 'sort' => PublicArticleCatalogue::SORT_DOWNLOADS], 'strlen')) }}"
                            @if ($sort === PublicArticleCatalogue::SORT_DOWNLOADS) aria-current="true" @endif
                            class="rounded-md py-1 hover:underline {{ $sort === PublicArticleCatalogue::SORT_DOWNLOADS ? 'font-semibold text-navy-900 underline' : 'text-navy-700' }}"
                        >{{ __('publications.public.sort_downloads') }}</a>
                    </li>
                </ul>
            </nav>
        </div>
        <p class="mt-1 text-sm text-navy-700">{{ __('publications.public.downloads_note') }}</p>

        @if ($total === 0)
            @if ($topic === '' && $search === '')
                <p class="mt-10 text-navy-800">{{ __('publications.public.empty_library') }}</p>
            @else
                <div class="mt-8 rounded-xl border border-dashed border-navy-800/30 px-6 py-12 text-center">
                    <h2 class="font-serif text-2xl font-semibold text-navy-900">{{ __('publications.public.empty_heading') }}</h2>
                    <p class="mt-3 text-navy-800">{{ __('publications.public.empty_body') }}</p>
                    <a
                        href="{{ PublicRoute::url('public.publications.index') }}"
                        class="mt-6 inline-flex items-center rounded-full border border-navy-800 px-4 py-2 text-sm font-medium hover:bg-cream-100"
                    >{{ __('publications.public.empty_clear') }}</a>
                </div>
            @endif
        @else
            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($articles as $article)
                    <li>
                        <article class="flex h-full flex-col rounded-xl border border-cream-200 bg-white p-6 shadow-sm">
                            <x-public.article-meta :topic="$article->topic" :issued-on="$article->issuedOn" />

                            <h2 class="mt-3 font-serif text-xl font-semibold text-navy-900">
                                <a href="{{ PublicRoute::url('public.publications.show', ['slug' => $article->slug]) }}" class="hover:underline">{{ $article->title }}</a>
                            </h2>

                            <p class="mt-3 line-clamp-4 text-sm text-navy-800">{{ $article->description }}</p>
                            <p class="mt-3 text-sm text-navy-700">{{ $article->authors }}</p>

                            <div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-cream-200 pt-4">
                                <a
                                    href="{{ PublicRoute::url('public.publications.download', ['slug' => $article->slug]) }}"
                                    aria-label="{{ __('publications.public.download_pdf_named', ['title' => $article->title]) }}"
                                    class="inline-flex items-center rounded-full bg-navy-900 px-4 py-2 text-sm font-medium text-cream-50 hover:bg-navy-800"
                                >{{ __('publications.public.download_pdf') }}</a>
                                <span class="text-sm text-navy-700">{{ trans_choice('publications.public.downloads', $article->downloadCount) }}</span>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>

            @if ($lastPage > 1)
                <nav aria-label="{{ __('publications.public.pagination_label') }}" class="mt-10 flex flex-wrap items-center justify-center gap-6 text-sm">
                    @if ($previousUrl !== null)
                        <a href="{{ $previousUrl }}" rel="prev" class="inline-flex items-center rounded-full border border-navy-800 px-4 py-2 font-medium hover:bg-cream-100">{{ __('publications.public.previous') }}</a>
                    @endif

                    <p class="text-navy-800">{{ __('publications.public.page_of', ['current' => $currentPage, 'last' => $lastPage]) }}</p>

                    @if ($nextUrl !== null)
                        <a href="{{ $nextUrl }}" rel="next" class="inline-flex items-center rounded-full border border-navy-800 px-4 py-2 font-medium hover:bg-cream-100">{{ __('publications.public.next') }}</a>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection
