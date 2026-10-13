@use('App\Support\PublicContact')
@use('App\Support\PublicRoute')

@php($contact = PublicContact::fromConfig())

{{--
    THE TOP BAR AND THE STICKY HEADER ARE SIBLINGS, NOT ONE <header>. A sticky
    element sticks only within its parent, so the bar and the header inside a single
    wrapper would scroll away together. As the design draws it, the bar scrolls off
    and only the header stays.

    The bar prints the phone first and then the email (the design's order), each
    only while it is set, through the same two components every other page uses, so
    PublicContact stays the only place that decides how either is written. The icons
    sit beside the links, not inside them: they are decoration. The centre's Arabic
    name takes the inline end of the bar. Below 640px it is allowed to wrap, which
    is what keeps a phone from scrolling sideways; from there up it stays on a line.
    The bar's links are small, so each has an invisible 44px hit area (tap-target).

    From 1024px the header is a row of the brand, the links and the portal pill. Below
    that the links fold into the menu (navigation.blade.php), and the pill, which does
    not fit beside the brand on a phone, moves into that menu's last item below 640px.
--}}
<div class="bg-navy text-caption text-on-navy">
    <x-public.container class="flex flex-wrap items-center gap-x-4.5 gap-y-1 py-2.25">
        @if ($contact->phone !== '' || $contact->email !== '')
            <ul aria-label="{{ __('public.header.contact_label') }}" class="flex flex-wrap items-center gap-x-4.5 gap-y-1">
                @if ($contact->phone !== '')
                    <li class="flex items-center gap-1.75">
                        <x-public.icon name="phone" :size="13" :round="false" class="text-gold" />
                        <x-public.phone-link class="tap-target whitespace-nowrap text-on-navy hover:text-gold-light focus-visible:outline-gold-light" />
                    </li>
                @endif
                @if ($contact->email !== '')
                    <li class="flex items-center gap-1.75">
                        <x-public.icon name="mail" :size="13" :round="false" class="text-gold" />
                        <x-public.email-link class="tap-target whitespace-nowrap text-on-navy hover:text-gold-light focus-visible:outline-gold-light" />
                    </li>
                @endif
            </ul>
        @endif

        <span class="ms-auto sm:whitespace-nowrap">
            <span lang="ar" dir="rtl" class="font-semibold tracking-[0.02em] text-gold">{{ config('public_site.brand.name_ar') }}</span>
        </span>
    </x-public.container>
</div>

<header class="sticky top-0 z-50 border-b border-navy/10 bg-cream/92 backdrop-blur-md">
    <x-public.container class="flex items-center gap-4 py-3.25">
        <a href="{{ PublicRoute::url('public.home') }}" class="flex-none rounded-control">
            <x-public.brand />
        </a>

        @include('public.partials.navigation')

        <div class="hidden flex-none sm:block">
            <x-public.portal-link />
        </div>
    </x-public.container>
</header>
