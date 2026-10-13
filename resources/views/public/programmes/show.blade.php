@extends('layouts.public')

{{--
    One programme. The facts are programme-facts, the same component the card
    uses: hours always, and price and start date only when NextUpcomingBatch found
    a planned batch. Without one there is no price and no date, only the contact
    call to action. No seats, capacity or enrolment count, and nothing on the
    course's own default price: a price with no batch behind it is a promise the
    centre has not made.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => $programme->name, 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <p><a href="{{ PublicRoute::url('public.programmes.index') }}" class="text-sm font-medium text-navy hover:underline">{{ __('public.programmes.back') }}</a></p>

        <h1 class="mt-4 font-display text-4xl text-navy">{{ $programme->name }}</h1>

        @if ($programme->description !== '')
            <p class="mt-4 text-lg text-body">{{ $programme->description }}</p>
        @endif

        <x-public.programme-facts :programme="$programme" class="mt-8 rounded-card border border-navy/9 bg-card p-6" />

        <section aria-labelledby="programme-ask-heading" class="mt-12">
            <h2 id="programme-ask-heading" class="font-display text-2xl text-navy">{{ __('public.programmes.ask_heading') }}</h2>
            <p class="mt-3 text-body">{{ __('public.programmes.ask_body') }}</p>
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="mt-4 inline-flex items-center rounded-pill bg-navy px-6 py-3 font-medium text-cream hover:bg-navy-hover"
            >{{ __('public.programmes.ask_link') }}</a>
        </section>
    </div>
@endsection
