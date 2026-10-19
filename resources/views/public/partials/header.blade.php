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

    THE LANGUAGE SWITCH takes the inline end of the bar, after the Arabic name
    (x-locale-switch; P4-T18a). It is the one place the page's direction changes the
    bar's order: in Arabic the contacts sit at the right and the switch at the left, and
    the switch's own thumb slides across that distance when the page changes language.

    TOUCH TARGETS. Below 1024px each contact link is a real 44px box and the bar has
    no padding of its own, so the boxes fill it: at 768px the bar is one 44px row, and
    on a phone the Arabic name wraps under the contacts with 8px above and below it.
    The switch's pill is 30px tall, so below 1024px it sits in a 44px box of its own and
    its link's tap-target extension (44 by 44) stays inside the bar instead of running
    off the page or under the sticky header.
    An invisible hit area would not do here: the bar is the first thing on the page, so
    the part of one above a 20px link would sit off the page and the part below it under
    the sticky header. From 1024px, where the pointer is precise, the links are the
    design's own line and the bar is the design's 38px (9px padding).

    From 1024px the header is a row of the brand, the links and the portal pill. Below
    that the links fold into the menu (navigation.blade.php), and the pill, which does
    not fit beside the brand on a phone, moves into that menu's last item below 640px.
    The row may wrap, as the design's does, so on a screen narrower than the brand and
    the Menu button together (320px) the button drops under the brand and nothing
    scrolls sideways.
--}}
<div class="bg-navy text-caption text-on-navy">
    <x-public.container class="flex flex-wrap items-center gap-x-4.5 py-0 lg:py-2.25">
        @if ($contact->phone !== '' || $contact->email !== '')
            <ul aria-label="{{ __('public.header.contact_label') }}" class="flex flex-wrap items-center gap-x-4.5">
                @if ($contact->phone !== '')
                    <li class="flex items-center gap-1.75">
                        <x-public.icon name="phone" :size="13" linecap="butt" linejoin="miter" class="text-gold" />
                        <x-public.phone-link class="flex min-h-11 items-center whitespace-nowrap text-on-navy hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0" />
                    </li>
                @endif
                @if ($contact->email !== '')
                    <li class="flex items-center gap-1.75">
                        <x-public.icon name="mail" :size="13" linecap="butt" linejoin="miter" class="text-gold" />
                        <x-public.email-link class="flex min-h-11 items-center whitespace-nowrap text-on-navy hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0" />
                    </li>
                @endif
            </ul>
        @endif

        <div class="ms-auto flex items-center justify-end gap-x-4.5">
            <span class="min-w-0 flex-[1_1_8rem] py-2 text-end sm:flex-none sm:whitespace-nowrap lg:py-0">
                <span lang="ar" dir="rtl" class="font-semibold tracking-[0.02em] text-gold">{{ config('public_site.brand.name_ar') }}</span>
            </span>

            <div class="flex min-h-11 items-center lg:min-h-0">
                <x-locale-switch />
            </div>
        </div>
    </x-public.container>
</div>

<header class="sticky top-0 z-50 border-b border-navy/10 bg-cream/92 backdrop-blur-md">
    <x-public.container class="flex flex-wrap items-center gap-x-4 gap-y-2.5 py-3.25">
        <a href="{{ PublicRoute::url('public.home') }}" class="flex-none rounded-control">
            <x-public.brand />
        </a>

        @include('public.partials.navigation')

        <div class="hidden flex-none sm:block">
            <x-public.portal-link />
        </div>
    </x-public.container>
</header>
