@extends('layouts.public')

{{--
    The home page: a hero, then a summary of each of About, Programmes, the
    verifier and Contact, each linking on to its own page.

    WHAT THE DESIGN DRAWS AND THIS PAGE LEAVES OUT. The stats strip shows figures
    the centre has not confirmed. The verifier is a link, not an inline form,
    because a public page holds no session and a form posted from it would carry
    no CSRF token. There is no publications section: it needs the articles of
    P4-T13, whose scope does not include this page.

    The copy is draft (lang/en/public.php, `home.*`) and states only what the
    system makes true. The programme cards are the first few of the same ordered
    list the programmes page shows; the card, not this page, decides what one says.
--}}
@use('App\Support\PublicRoute')

@section('content')
    <section aria-labelledby="home-heading" class="bg-navy-900 text-cream-50">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-[2fr_1fr] md:py-24">
            <div>
                <h1 id="home-heading" class="font-serif text-4xl font-semibold md:text-5xl">{{ __('public.home.heading', ['name' => config('app.name')]) }}</h1>
                <p class="mt-4 max-w-xl text-lg text-cream-200">{{ __('public.home.lead') }}</p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a
                        href="{{ PublicRoute::url('public.programmes.index') }}"
                        class="inline-flex items-center rounded-full bg-gold-500 px-6 py-3 font-medium text-navy-900 hover:bg-gold-400 focus-visible:outline-gold-400"
                    >{{ __('public.home.programmes_link') }}</a>
                    <a
                        href="{{ PublicRoute::url('verify.certificates.form') }}"
                        class="inline-flex items-center rounded-full border border-cream-100/40 px-6 py-3 font-medium text-cream-50 hover:bg-navy-800 focus-visible:outline-gold-400"
                    >{{ __('public.links.verify_certificate') }}</a>
                </div>
            </div>

            <div
                aria-hidden="true"
                class="hidden size-56 place-items-center justify-self-center rounded-full bg-navy-800 font-serif text-7xl text-gold-400 ring-1 ring-gold-500 md:grid"
            >{{ __('public.brand.mark') }}</div>
        </div>
    </section>

    <section aria-labelledby="home-about-heading" class="bg-cream-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 id="home-about-heading" class="font-serif text-3xl font-semibold">{{ __('public.home.about_heading') }}</h2>
            <p class="mt-4 max-w-2xl text-lg text-navy-800">{{ __('public.home.about_body') }}</p>
            <a href="{{ PublicRoute::url('public.about') }}" class="mt-6 inline-block font-medium text-navy-700 underline hover:text-navy-900">{{ __('public.home.about_link') }}</a>
        </div>
    </section>

    <section aria-labelledby="home-programmes-heading" class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 id="home-programmes-heading" class="font-serif text-3xl font-semibold">{{ __('public.home.programmes_heading') }}</h2>
                <a
                    href="{{ PublicRoute::url('public.programmes.index') }}"
                    class="inline-flex items-center rounded-full border border-navy-800 px-4 py-2 text-sm font-medium hover:bg-cream-100"
                >{{ __('public.home.programmes_all') }}</a>
            </div>

            @if ($programmes === [])
                <p class="mt-8 text-navy-800">{{ __('public.programmes.empty') }}</p>
            @else
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($programmes as $programme)
                        <x-public.programme-card :course="$programme['course']" :card="$programme['card']" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section aria-labelledby="home-verify-heading" class="bg-navy-900 text-cream-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 id="home-verify-heading" class="font-serif text-3xl font-semibold">{{ __('public.home.verify_heading') }}</h2>
            <p class="mt-4 max-w-2xl text-lg text-cream-200">{{ __('public.home.verify_body') }}</p>
            <a
                href="{{ PublicRoute::url('verify.certificates.form') }}"
                class="mt-6 inline-flex items-center rounded-full bg-gold-500 px-6 py-3 font-medium text-navy-900 hover:bg-gold-400 focus-visible:outline-gold-400"
            >{{ __('public.links.verify_certificate') }}</a>
        </div>
    </section>

    <section aria-labelledby="home-contact-heading" class="bg-cream-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 id="home-contact-heading" class="font-serif text-3xl font-semibold">{{ __('public.home.contact_heading') }}</h2>
            <p class="mt-4 max-w-2xl text-lg text-navy-800">{{ __('public.home.contact_body') }}</p>

            {{-- dir="ltr": an email address or a number keeps its order inside right-to-left text. --}}
            <ul class="mt-6 flex flex-wrap gap-x-8 gap-y-2 font-medium">
                @if ($contact['email'] !== '')
                    <li><a href="mailto:{{ $contact['email'] }}" dir="ltr" class="text-navy-700 underline hover:text-navy-900">{{ $contact['email'] }}</a></li>
                @endif
                @if ($contact['phone'] !== '')
                    <li><a href="tel:{{ $contact['telephone'] }}" dir="ltr" class="text-navy-700 underline hover:text-navy-900">{{ $contact['phone'] }}</a></li>
                @endif
            </ul>

            <a href="{{ PublicRoute::url('public.contact') }}" class="mt-6 inline-block font-medium text-navy-700 underline hover:text-navy-900">{{ __('public.home.contact_link') }}</a>
        </div>
    </section>
@endsection
