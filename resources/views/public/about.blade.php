@extends('layouts.public')

{{--
    About. The copy is draft (lang/en/public.php, `about.*`). Of the design's
    three feature cards only "Verifiable certification" is kept, because it is the
    one the system makes true; the other two claimed things about the faculty and
    the teaching format that the centre has not confirmed.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('public.about.heading'), 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="font-display text-4xl text-navy">{{ __('public.about.heading') }}</h1>

        <p class="mt-6 text-lg text-body">{{ __('public.about.intro') }}</p>
        <p class="mt-4 text-lg text-body">{{ __('public.about.register') }}</p>
        <p class="mt-4 text-lg text-body">{{ __('public.about.questions') }}</p>

        <section aria-labelledby="about-certification-heading" class="mt-10 rounded-card border border-navy/9 bg-card p-6">
            <h2 id="about-certification-heading" class="font-display text-2xl text-navy">{{ __('public.about.certification_heading') }}</h2>
            <p class="mt-3 text-body">{{ __('public.about.certification_body') }}</p>
            <a href="{{ PublicRoute::url('verify.certificates.form') }}" class="mt-4 inline-block font-medium text-navy underline hover:text-gold-dark">{{ __('public.links.verify_certificate') }}</a>
        </section>

        <div class="mt-10 flex flex-wrap gap-3">
            <a
                href="{{ PublicRoute::url('public.programmes.index') }}"
                class="inline-flex items-center rounded-pill bg-navy px-6 py-3 font-medium text-cream hover:bg-navy-hover"
            >{{ __('public.about.programmes_link') }}</a>
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="inline-flex items-center rounded-pill border border-navy/20 px-6 py-3 font-medium text-navy hover:bg-navy hover:text-cream"
            >{{ __('public.about.contact_link') }}</a>
        </div>
    </div>
@endsection
