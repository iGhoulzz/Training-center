@extends('layouts.public')

{{--
    Every active course as a card, in the controller's order, under the library's navy
    header (the prototype shows the programmes only as a section of the home page). The
    h1 is the page's header and each card's name is an h2, so the headings stay in
    order. Which courses show, and what each card says, is ProgrammeController's and the
    card's business; this page only lays them out, on white as the design's section is.
--}}
@use('App\Support\PublicRoute')

@section('title')
    <x-public.page-title :page="__('public.programmes.heading')" />
@endsection

@section('content')
    <x-public.page-header :title="__('public.programmes.heading')" :intro="__('public.programmes.intro')" />

    <section class="border-b border-navy/8 bg-card">
        <x-public.container class="py-20">
            @if ($programmes === [])
                <p class="text-base text-body">{{ __('public.programmes.empty') }}</p>
                <x-public.button :href="PublicRoute::url('public.contact')" variant="outline" size="sm" class="mt-4">{{ __('public.links.contact') }}</x-public.button>
            @else
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(272px,100%),1fr))] gap-4">
                    @foreach ($programmes as $programme)
                        <x-public.programme-card :programme="$programme" :level="2" />
                    @endforeach
                </div>
            @endif
        </x-public.container>
    </section>
@endsection
