@extends('layouts.public')

{{--
    About. The copy is draft (lang/en/public.php, `about.*`). The prototype shows
    About only as a section of the home page, so this page takes the library's navy
    header and then that section's two columns: the paragraphs on the left and, on the
    right, the pillar. Of the design's three feature cards only "Verifiable
    certification" is kept, because it is the one the system makes true; the other two
    claimed things about the faculty and the teaching format that the centre has not
    confirmed. The two buttons are the page's onward links.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('public.about.heading'), 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <x-public.page-header :title="__('public.about.heading')" />

    <x-public.container class="py-22">
        <div class="flex flex-wrap items-start gap-14">
            <div class="min-w-0 flex-[1_1_380px]">
                <p class="max-w-140 text-base leading-[1.8] text-pretty text-body lg:text-prose">{{ __('public.about.intro') }}</p>
                <p class="mt-4 max-w-140 text-base leading-[1.8] text-pretty text-body lg:text-prose">{{ __('public.about.register') }}</p>
                <p class="mt-4 max-w-140 text-base leading-[1.8] text-pretty text-body lg:text-prose">{{ __('public.about.questions') }}</p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <x-public.button :href="PublicRoute::url('public.programmes.index')" variant="navy">{{ __('public.about.programmes_link') }}</x-public.button>
                    <x-public.button :href="PublicRoute::url('public.contact')" variant="outline">{{ __('public.about.contact_link') }}</x-public.button>
                </div>
            </div>

            <div class="grid min-w-0 flex-[1_1_320px] gap-3.25">
                <x-public.pillar icon="award" :level="2" :title="__('public.about.certification_heading')">{{ __('public.about.certification_body') }}</x-public.pillar>
            </div>
        </div>
    </x-public.container>
@endsection
