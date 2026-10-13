@extends('layouts.public')

{{--
    Every active course as a card, in the controller's order. The h1 is the page
    and each card's name is an h2, so the headings stay in order. Which courses
    show, and what each card says, is ProgrammeController's and the card's
    business; this page only lays them out.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('public.programmes.heading'), 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="font-display text-4xl text-navy">{{ __('public.programmes.heading') }}</h1>
        <p class="mt-4 max-w-2xl text-lg text-body">{{ __('public.programmes.intro') }}</p>

        @if ($programmes === [])
            <p class="mt-10 text-body">{{ __('public.programmes.empty') }}</p>
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="mt-4 inline-flex items-center rounded-pill border border-navy/20 px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-cream"
            >{{ __('public.links.contact') }}</a>
        @else
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($programmes as $programme)
                    <x-public.programme-card :programme="$programme" :level="2" />
                @endforeach
            </div>
        @endif
    </div>
@endsection
