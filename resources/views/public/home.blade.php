@extends('layouts.public')

{{--
    The home page, section by section as the design draws it: the hero, About,
    Programmes, the certificate verifier's box, the publications teaser and Contact.

    WHAT THE DESIGN DRAWS AND THIS PAGE LEAVES OUT (owner and plan decisions). No
    location pill and no stats strip: the centre has not sent an address or confirmed
    a figure. No seats pill, category tag or Arabic subtitle on a card or a teaser
    entry: there is no data for them, and a projection carries one language. Only the
    About pillar the system makes true. No "Try ..." buttons under the verifier, and no
    result area in it. No download toast and no embedded map.

    THE VERIFIER'S BOX is a read-only `method="get"` form (owner, 2026-10-13), the one
    kind of form a sessionless page may hold: it carries the reference to the
    verifier's own lookup route, under that group's headers and limiter, and the result
    shows on the verifier's own page. Nothing is stored and no token is needed.

    THE TEASER shows only while there are published articles to show, and shows the
    ones PageController hands over: a closed projection of at most three, newest first.
    The page never queries.

    The copy is draft (lang/en/public.php, `home.*`) and states only what the system
    makes true. The programme cards are the first few of the same ordered list the
    programmes page shows; the card, not this page, decides what one says.

    THE H1 IS THE CENTRE'S NAME with the phrase `home.heading_emphasis` set in italic
    gold wherever the translated heading contains it, as the design draws "Legal
    Studies". The three parts are each escaped; nothing is printed raw. A heading that
    does not contain the phrase is printed plainly.
--}}
@use('App\Support\PublicContact')
@use('App\Support\PublicRoute')

@php
    $contact = PublicContact::fromConfig();
    $heading = __('public.home.heading', ['name' => config('public_site.brand.name')]);
    $emphasis = __('public.home.heading_emphasis');
    $emphasisAt = $emphasis === '' ? false : mb_strpos($heading, $emphasis);
@endphp

@section('content')
    <section aria-labelledby="home-heading" class="relative overflow-hidden bg-navy text-cream">
        <div aria-hidden="true" class="absolute inset-0 bg-radial-[circle_at_78%_22%] from-gold/26 to-transparent to-46%"></div>
        <div aria-hidden="true" class="absolute inset-0 bg-radial-[circle_at_12%_82%] from-gold/13 to-transparent to-44%"></div>
        <div aria-hidden="true" class="absolute -end-22.5 -top-22.5 size-105 rounded-pill border border-gold/16"></div>
        <div aria-hidden="true" class="absolute -end-7.5 -top-7.5 size-75 rounded-pill border border-gold/12"></div>

        <x-public.container class="relative flex flex-wrap items-center gap-13 pt-20.5 pb-23">
            <div class="min-w-0 flex-[1_1_460px] motion-safe:animate-rise">
                <h1 id="home-heading" class="font-display text-display text-balance">@if ($emphasisAt === false){{ $heading }}@else{{ mb_substr($heading, 0, $emphasisAt) }}<span class="text-gold-light italic">{{ $emphasis }}</span>{{ mb_substr($heading, $emphasisAt + mb_strlen($emphasis)) }}@endif</h1>
                <p class="mt-5 max-w-140 text-lead text-pretty text-cream/76">{{ __('public.home.lead') }}</p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <x-public.button href="#programmes" variant="primary" size="lg">
                        {{ __('public.home.programmes_link') }}
                        <x-public.icon name="arrow-right" :size="15" :stroke-width="2.2" linejoin="miter" class="rtl:-scale-x-100" />
                    </x-public.button>
                    <x-public.button href="#verify" variant="ghost" size="lg">{{ __('public.links.verify_certificate') }}</x-public.button>
                </div>
            </div>

            <div class="flex min-w-0 flex-[0_1_340px] justify-center motion-safe:animate-rise-slow">
                <div class="relative flex size-75 items-center justify-center">
                    <div aria-hidden="true" class="absolute inset-0 rounded-pill bg-radial from-gold/22 to-transparent to-68%"></div>
                    <img
                        src="{{ asset(config('public_site.brand.logo')) }}"
                        alt=""
                        width="258"
                        height="258"
                        decoding="async"
                        class="relative size-64.5 rounded-pill border-2 border-gold/50 object-cover object-center shadow-seal motion-safe:animate-float"
                    >
                </div>
            </div>
        </x-public.container>
    </section>

    <section aria-labelledby="home-about-heading">
        <x-public.container class="pt-22 pb-18">
            <div class="flex flex-wrap items-start gap-14">
                <div class="min-w-0 flex-[1_1_380px]">
                    <x-public.section-heading id="home-about-heading" size="lg" balance :eyebrow="__('public.home.about_heading')" :title="__('public.home.about_title')" />
                    <p class="mt-5 max-w-140 text-base leading-[1.8] text-pretty text-body lg:text-prose">{{ __('public.home.about_body') }}</p>
                    <p class="mt-4 max-w-140 text-base leading-[1.8] text-pretty text-body lg:text-prose">{{ __('public.home.about_register') }}</p>
                </div>

                <div class="grid min-w-0 flex-[1_1_320px] gap-3.25">
                    <x-public.pillar icon="award" :title="__('public.about.certification_heading')">{{ __('public.about.certification_body') }}</x-public.pillar>
                </div>
            </div>
        </x-public.container>
    </section>

    <section id="programmes" aria-labelledby="home-programmes-heading" class="border-y border-navy/8 bg-card">
        <x-public.container class="py-20">
            <div class="mb-8.5 flex flex-wrap items-end gap-5">
                <x-public.section-heading id="home-programmes-heading" class="min-w-65 flex-1" :eyebrow="__('public.links.programmes')" :title="__('public.home.programmes_title')" />
                <x-public.button :href="PublicRoute::url('public.contact')" variant="outline" class="leading-[normal] max-lg:min-h-11">{{ __('public.home.request_seat') }}</x-public.button>
            </div>

            @if ($programmes === [])
                <p class="text-base text-body">{{ __('public.programmes.empty') }}</p>
            @else
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(272px,100%),1fr))] gap-4">
                    @foreach ($programmes as $programme)
                        <x-public.programme-card :programme="$programme" />
                    @endforeach
                </div>
            @endif
        </x-public.container>
    </section>

    <section id="verify" aria-labelledby="home-verify-heading" class="relative overflow-hidden bg-navy text-cream">
        <div aria-hidden="true" class="absolute inset-0 bg-radial-[circle_at_20%_30%] from-gold/18 to-transparent to-45%"></div>

        <x-public.container class="relative flex flex-wrap items-start gap-13 py-20">
            <div class="min-w-0 flex-[1_1_340px]">
                <x-public.section-heading id="home-verify-heading" tone="dark" balance :eyebrow="__('public.home.verify_eyebrow')" :title="__('public.home.verify_heading')" />
                <p class="mt-4 max-w-120 text-base leading-[1.8] text-pretty text-cream/74 lg:text-prose">{{ __('public.home.verify_lead') }}</p>
            </div>

            <div class="min-w-0 flex-[1_1_400px] rounded-panel border border-cream/14 bg-cream/5 p-6.5 backdrop-blur-[6px]">
                <form method="get" action="{{ PublicRoute::url('verify.certificates.lookup') }}">
                    <label for="home-verify-reference" class="mb-2.5 block text-field-label text-cream/62 uppercase">{{ __('public.home.verify_label') }}</label>

                    <div class="flex flex-wrap gap-2.5">
                        <input
                            id="home-verify-reference"
                            type="text"
                            name="reference"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('public.home.verify_placeholder') }}"
                            class="min-w-0 flex-[1_1_190px] rounded-field border border-cream/22 bg-navy/50 px-4 py-3.5 text-base tracking-[0.03em] text-cream placeholder:text-cream/55 focus:border-gold focus-visible:outline-gold-light"
                        >
                        <button
                            type="submit"
                            class="flex-none cursor-pointer rounded-field bg-gold px-6 py-3.5 text-sm leading-[normal] font-semibold whitespace-nowrap text-ink hover:bg-gold-light focus-visible:outline-gold-light"
                        >{{ __('public.home.verify_submit') }}</button>
                    </div>
                </form>

                <p class="mt-4.5 text-base leading-[1.6] text-cream/62 lg:text-caption">{{ __('public.home.verify_note') }}</p>
            </div>
        </x-public.container>
    </section>

    @if ($articles !== [])
        <section id="publications" aria-labelledby="home-publications-heading">
            <x-public.container class="py-21">
                <div class="mb-6.5 flex flex-wrap items-end gap-5">
                    <x-public.section-heading id="home-publications-heading" class="min-w-65 flex-1" :eyebrow="__('public.links.publications')" :title="__('public.home.publications_title')" :intro="__('public.home.publications_intro')" />
                    <x-public.button :href="PublicRoute::url('public.publications.index')" variant="outline" class="leading-[normal] max-lg:min-h-11">
                        {{ __('public.home.publications_all') }}
                        <x-public.icon name="arrow-right" :size="14" linejoin="miter" class="rtl:-scale-x-100" />
                    </x-public.button>
                </div>

                <div class="flex flex-col gap-3.5">
                    @foreach ($articles as $article)
                        <x-public.card as="article" hover="lift" class="flex flex-wrap items-start gap-5.5 p-6">
                            <x-public.pdf-thumb />

                            <div class="min-w-0 flex-[1_1_300px]">
                                <x-public.article-meta :topic="$article->topic" :issued-on="$article->issuedOn" class="mb-2 gap-x-2.5 gap-y-1" />

                                <h3 class="font-display text-teaser-title text-balance text-navy">
                                    <a href="{{ PublicRoute::url('public.publications.show', ['slug' => $article->slug]) }}" class="tap-target block hover:text-gold-dark">{{ $article->title }}</a>
                                </h3>

                                <p class="mt-2.75 max-w-160 text-base leading-[1.7] text-pretty text-body-secondary lg:text-sm">{{ $article->description }}</p>
                                <p class="mt-2.75 text-caption text-meta">{{ $article->authors }}</p>
                            </div>

                            <div class="flex min-w-37.5 flex-none flex-col gap-2">
                                <x-public.button
                                    :href="PublicRoute::url('public.publications.download', ['slug' => $article->slug])"
                                    variant="control"
                                    size="control"
                                    :aria-label="__('publications.public.download_pdf_named', ['title' => $article->title])"
                                >
                                    <x-public.icon name="download" :size="14" :stroke-width="2.2" linejoin="miter" class="text-gold" />
                                    {{ __('publications.public.download_pdf') }}
                                </x-public.button>
                                <x-public.button
                                    :href="PublicRoute::url('public.publications.show', ['slug' => $article->slug])"
                                    variant="control-outline"
                                    size="control"
                                    :aria-label="__('publications.public.read_more_named', ['title' => $article->title])"
                                >{{ __('publications.public.read_more') }}</x-public.button>
                                <p class="text-center text-micro text-meta">{{ trans_choice('publications.public.downloads', $article->downloadCount) }}</p>
                            </div>
                        </x-public.card>
                    @endforeach
                </div>

                <p class="mt-4 text-caption text-meta">{{ __('publications.public.downloads_note') }}</p>
            </x-public.container>
        </section>
    @endif

    @if ($contact->email !== '' || $contact->phone !== '' || $contact->mapUrl !== '')
        <section id="contact" aria-labelledby="home-contact-heading" class="border-t border-navy/8 bg-card">
            <x-public.container class="py-20">
                <x-public.contact-details heading-id="home-contact-heading" />
            </x-public.container>
        </section>
    @endif
@endsection
