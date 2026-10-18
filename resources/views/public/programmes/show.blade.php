@extends('layouts.public')

{{--
    One programme, under the article header's 860px column (the prototype has no
    programme page, so it borrows the article's layout): a back link to the programmes,
    the name as the h1, then the description at the article's reading size, the facts in
    a white card, and the ask section.

    The facts are programme-facts, the same component the card uses: hours always, and
    price and start date only when NextUpcomingBatch found a planned batch. Without one
    there is no price and no date, only the contact call to action. No seats, capacity or
    enrolment count, and nothing on the course's own default price: a price with no batch
    behind it is a promise the centre has not made.
--}}
@use('App\Support\PublicRoute')

@section('title')
    <x-public.page-title :page="$programme->name" />
@endsection

@section('content')
    <x-public.page-header size="narrow" :back-href="PublicRoute::url('public.programmes.index')" :back-label="__('public.programmes.back')" :title="$programme->name" />

    <x-public.container size="narrow" class="pt-11 pb-20">
        @if ($programme->description !== '')
            <p class="text-article-lead text-pretty wrap-anywhere text-ink-soft">{{ $programme->description }}</p>
        @endif

        <x-public.card variant="box" class="mt-8 p-5">
            <x-public.programme-facts :programme="$programme" class="gap-3" />
        </x-public.card>

        <section aria-labelledby="programme-ask-heading" class="mt-12">
            <h2 id="programme-ask-heading" class="font-display text-2xl leading-[1.3] text-navy">{{ __('public.programmes.ask_heading') }}</h2>
            <p class="mt-3 text-base text-body">{{ __('public.programmes.ask_body') }}</p>
            <x-public.button :href="PublicRoute::url('public.contact')" variant="navy" class="mt-4">{{ __('public.programmes.ask_link') }}</x-public.button>
        </section>
    </x-public.container>
@endsection
