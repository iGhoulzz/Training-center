@extends('layouts.public')

{{--
    The minimal page that gives `/` somewhere to go now the stock welcome page is
    gone. P4-T12 replaces it with the real home page, so it claims nothing about
    the centre: no figures, no accreditation, no programme list.
--}}
@use('App\Support\PublicRoute')

@section('content')
    <section class="mx-auto max-w-3xl px-4 py-16">
        <h1 class="font-serif text-4xl font-semibold">{{ __('public.home.heading', ['name' => config('app.name')]) }}</h1>

        <p class="mt-4 text-lg text-navy-800">{{ __('public.home.lead') }}</p>

        <a
            href="{{ PublicRoute::url('verify.certificates.form') }}"
            class="mt-8 inline-flex items-center rounded-full bg-navy-900 px-6 py-3 font-medium text-cream-50 hover:bg-navy-800"
        >{{ __('public.links.verify_certificate') }}</a>
    </section>
@endsection
