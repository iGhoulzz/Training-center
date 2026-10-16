@extends('layouts.public')

{{--
    The publications library: the navy header, a search box and the sort button, the
    topic chips, the count line, one card per article and the page links. Which articles
    show, in what order and how many to a page is PublicationController's and
    PublicArticleCatalogue's business; this page only lays them out.

    NOTHING HERE IS A MODEL. $articles is a list of PublicArticle projections,
    $topics a list of [topic, count] pairs, and everything else a string, a number
    or null, including the previous and next page URLs.

    THE SEARCH BOX IS THE ONE FORM A PUBLIC PAGE MAY HOLD, and it is a read-only
    GET form: it writes q, topic and sort into the URL and nothing else, so it
    needs no session and no CSRF token (docs/ENGINEERING.md, "Frontend (phase 4)").
    The hidden inputs keep the topic and the sort across a new search. The design draws
    no submit button, so the search has none to see: Enter submits it, and the button is
    there for the keyboard and for assistive technology, visible only while it has focus.

    THE SORT IS ONE BUTTON, as the design draws it. Its text is the order the list is
    in now, and it is a link to the other order with the search and topic kept. Its
    accessible name says both, so a screen reader hears what it does.

    Every link, the form's action included, goes through PublicRoute. The links
    that keep the filters drop the empty ones (array_filter with strlen keeps a
    search for "0"), so an unfiltered library has a clean URL.
--}}
@use('App\Domain\Publications\Queries\PublicArticleCatalogue')
@use('App\Support\PublicRoute')

@php
    $newestFirst = $sort === PublicArticleCatalogue::SORT_NEWEST;
    $otherSort = $newestFirst ? PublicArticleCatalogue::SORT_DOWNLOADS : PublicArticleCatalogue::SORT_NEWEST;
    $currentLabel = $newestFirst ? __('publications.public.sort_newest') : __('publications.public.sort_downloads');
    $otherLabel = $newestFirst ? __('publications.public.sort_downloads') : __('publications.public.sort_newest');
@endphp

@section('title')
    {{ __('public.page_title', ['page' => __('publications.public.heading'), 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <x-public.page-header
        :back-href="PublicRoute::url('public.home')"
        :back-label="__('publications.public.back_home')"
        :eyebrow="__('publications.public.library_eyebrow')"
        :title="__('publications.public.heading')"
        :intro="__('publications.public.intro')"
    />

    <x-public.container class="pt-8 pb-21">
        <div class="mb-5.5 flex flex-wrap items-center gap-3">
            <form
                method="get"
                action="{{ PublicRoute::url('public.publications.index') }}"
                role="search"
                class="flex min-w-0 flex-[1_1_240px] items-center gap-3"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2.5 rounded-field border border-navy/14 bg-card px-3.75 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-navy-hover">
                    <x-public.icon name="search" :size="16" class="text-meta" />
                    <label for="publications-search" class="sr-only">{{ __('publications.public.search_label') }}</label>
                    <input
                        id="publications-search"
                        type="search"
                        name="q"
                        value="{{ $search }}"
                        placeholder="{{ __('publications.public.search_placeholder') }}"
                        class="min-w-0 flex-1 border-0 bg-transparent py-3 text-base text-ink outline-none placeholder:text-meta"
                    >
                </div>

                @if ($topic !== '')
                    <input type="hidden" name="topic" value="{{ $topic }}">
                @endif
                <input type="hidden" name="sort" value="{{ $sort }}">

                <button
                    type="submit"
                    class="sr-only focus:not-sr-only focus:rounded-field focus:bg-navy focus:px-4.25 focus:py-3 focus:text-compact focus:font-semibold focus:text-cream"
                >{{ __('publications.public.search_submit') }}</button>
            </form>

            <x-public.button
                :href="PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'topic' => $topic, 'sort' => $otherSort], 'strlen'))"
                variant="toggle"
                size="field"
                :aria-label="__('publications.public.sort_switch', ['current' => $currentLabel, 'next' => $otherLabel])"
            >{{ $currentLabel }}</x-public.button>
        </div>

        <nav aria-label="{{ __('publications.public.topics_label') }}" class="mb-6">
            <ul class="flex flex-wrap gap-2">
                <li>
                    <x-public.chip
                        :href="PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'sort' => $sort], 'strlen'))"
                        :selected="$topic === ''"
                        :name="__('publications.public.topic_all')"
                        :count="array_sum(array_column($topics, 'count'))"
                    />
                </li>

                @foreach ($topics as $entry)
                    <li>
                        <x-public.chip
                            :href="PublicRoute::url('public.publications.index', array_filter(['q' => $search, 'topic' => $entry['topic'], 'sort' => $sort], 'strlen'))"
                            :selected="mb_strtolower($topic) === mb_strtolower($entry['topic'])"
                            :name="$entry['topic']"
                            :count="$entry['count']"
                        />
                    </li>
                @endforeach
            </ul>
        </nav>

        <p class="text-compact wrap-anywhere text-meta">
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
        <p class="mt-1 mb-4 text-caption text-meta">{{ __('publications.public.downloads_note') }}</p>

        @if ($total === 0)
            <div class="rounded-card border border-dashed border-navy/18 bg-card px-6 py-15 text-center">
                @if ($topic === '' && $search === '')
                    <p class="text-base text-body">{{ __('publications.public.empty_library') }}</p>
                @else
                    <h2 class="font-display text-2xl leading-[1.6] text-navy">{{ __('publications.public.empty_heading') }}</h2>
                    <p class="mt-2 text-base text-meta lg:text-sm">{{ __('publications.public.empty_body') }}</p>
                    <x-public.button :href="PublicRoute::url('public.publications.index')" variant="outline" class="mt-6">{{ __('publications.public.empty_clear') }}</x-public.button>
                @endif
            </div>
        @else
            <ul class="grid grid-cols-[repeat(auto-fit,minmax(min(296px,100%),1fr))] gap-4">
                @foreach ($articles as $article)
                    <li>
                        <x-public.card as="article" hover="lift" class="flex h-full flex-col gap-3.25 p-5.5">
                            <x-public.article-meta :topic="$article->topic" :issued-on="$article->issuedOn" :issued-label="false" class="gap-x-2.25 gap-y-1" />

                            <h2 class="font-display text-library-title text-balance text-navy">
                                <a href="{{ PublicRoute::url('public.publications.show', ['slug' => $article->slug]) }}" class="tap-target block wrap-anywhere hover:text-gold-dark">{{ $article->title }}</a>
                            </h2>

                            <p class="line-clamp-4 text-base leading-[1.65] text-pretty wrap-anywhere text-body-secondary lg:text-ui">{{ $article->description }}</p>
                            <p class="mt-auto text-small wrap-anywhere text-meta">{{ $article->authors }}</p>

                            <div class="flex flex-wrap items-center gap-2 border-t border-navy/8 pt-3.25">
                                <x-public.button
                                    :href="PublicRoute::url('public.publications.download', ['slug' => $article->slug])"
                                    variant="control"
                                    size="library"
                                    :aria-label="__('publications.public.download_pdf_named', ['title' => $article->title])"
                                >
                                    <x-public.icon name="download" :size="13" :stroke-width="2.2" linejoin="miter" class="text-gold" />{{ __('publications.public.pdf') }}
                                </x-public.button>
                                <span class="text-note text-meta">{{ trans_choice('publications.public.downloads', $article->downloadCount) }}</span>
                            </div>
                        </x-public.card>
                    </li>
                @endforeach
            </ul>

            @if ($lastPage > 1)
                <nav aria-label="{{ __('publications.public.pagination_label') }}" class="mt-10 flex flex-wrap items-center justify-center gap-6 text-sm">
                    @if ($previousUrl !== null)
                        <x-public.button :href="$previousUrl" variant="outline" rel="prev" class="leading-[normal] max-lg:min-h-11">{{ __('publications.public.previous') }}</x-public.button>
                    @endif

                    <p class="text-base text-body lg:text-sm">{{ __('publications.public.page_of', ['current' => $currentPage, 'last' => $lastPage]) }}</p>

                    @if ($nextUrl !== null)
                        <x-public.button :href="$nextUrl" variant="outline" rel="next" class="leading-[normal] max-lg:min-h-11">{{ __('publications.public.next') }}</x-public.button>
                    @endif
                </nav>
            @endif
        @endif
    </x-public.container>
@endsection
