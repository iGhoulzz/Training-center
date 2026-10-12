@extends('layouts.public')

{{--
    About. The copy is draft (lang/en/public.php, `about.*`). Of the design's
    three feature cards only "Verifiable certification" is kept, because it is the
    one the system makes true; the other two claimed things about the faculty and
    the teaching format that the centre has not confirmed.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('public.about.heading'), 'name' => config('app.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="font-serif text-4xl font-semibold">{{ __('public.about.heading') }}</h1>

        <p class="mt-6 text-lg text-navy-800">{{ __('public.about.intro') }}</p>
        <p class="mt-4 text-lg text-navy-800">{{ __('public.about.register') }}</p>
        <p class="mt-4 text-lg text-navy-800">{{ __('public.about.questions') }}</p>

        <section aria-labelledby="about-certification-heading" class="mt-10 rounded-xl border border-cream-200 bg-white p-6">
            <h2 id="about-certification-heading" class="font-serif text-2xl font-semibold">{{ __('public.about.certification_heading') }}</h2>
            <p class="mt-3 text-navy-800">{{ __('public.about.certification_body') }}</p>
            <a href="{{ PublicRoute::url('verify.certificates.form') }}" class="mt-4 inline-block font-medium text-navy-700 underline hover:text-navy-900">{{ __('public.links.verify_certificate') }}</a>
        </section>

        <div class="mt-10 flex flex-wrap gap-3">
            <a
                href="{{ PublicRoute::url('public.programmes.index') }}"
                class="inline-flex items-center rounded-full bg-navy-900 px-6 py-3 font-medium text-cream-50 hover:bg-navy-800"
            >{{ __('public.about.programmes_link') }}</a>
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="inline-flex items-center rounded-full border border-navy-800 px-6 py-3 font-medium hover:bg-cream-100"
            >{{ __('public.about.contact_link') }}</a>
        </div>
    </div>
@endsection
