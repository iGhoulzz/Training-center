@use('App\Support\PublicRoute')
@use('Illuminate\Support\Facades\Route')

{{--
    ONE LIST, RENDERED TWICE. A closed <details> cannot be forced open by CSS
    alone in every browser, and this page runs no script, so the narrow-screen
    menu and the wide-screen row are two elements built from the same list: one
    is hidden at each width. A page added later is ONE entry below, not two
    edits that can disagree. Every link goes through PublicRoute, never route().

    Each entry is [url, label, is the current section], in the order they are
    shown: About, Programmes, Publications, Verify, Contact (P4-T12, P4-T13).

    THE CURRENT SECTION is found by route name, so Programmes is current on the
    index and on every programme page, and Publications on the library, an article
    and its download. Each section also names its `ar.` twin (P4-T18 registers
    the Arabic pages under that prefix), so the link stays marked in Arabic. Its
    link carries aria-current="page", which is what a screen reader announces, and
    the gold-dark colour the design gives the active link. The verifier is not a
    page of this layout, so its entry is never current. Route::is() reads the
    route's name and nothing else, so it needs no session.

    Below 1024px the links are the <details> menu: a pill-shaped "Menu" button of
    at least 44px, and a white panel of 44px rows. Below 640px the panel's last item
    is the portal pill, which has no room in the bar there (header.blade.php).
--}}
@php
    $links = [
        [PublicRoute::url('public.about'), __('public.links.about'), Route::is('public.about', 'ar.public.about')],
        [PublicRoute::url('public.programmes.index'), __('public.links.programmes'), Route::is('public.programmes.*', 'ar.public.programmes.*')],
        [PublicRoute::url('public.publications.index'), __('public.links.publications'), Route::is('public.publications.*', 'ar.public.publications.*')],
        [PublicRoute::url('verify.certificates.form'), __('public.nav.verify'), false],
        [PublicRoute::url('public.contact'), __('public.links.contact'), Route::is('public.contact', 'ar.public.contact')],
    ];
@endphp

<nav aria-label="{{ __('public.nav.label') }}" class="ms-auto">
    <details class="group relative lg:hidden">
        <summary class="inline-flex min-h-11 min-w-11 cursor-pointer list-none items-center justify-center rounded-pill border border-navy/20 px-4 text-ui font-semibold text-navy hover:bg-navy/5 group-open:border-navy group-open:bg-navy group-open:text-cream [&::-webkit-details-marker]:hidden">{{ __('public.nav.menu') }}</summary>

        <ul class="absolute end-0 top-full z-20 mt-2 w-60 rounded-field border border-navy/10 bg-card p-2 shadow-card-hover">
            @foreach ($links as [$href, $label, $current])
                <li>
                    <a
                        href="{{ $href }}"
                        @if ($current) aria-current="page" @endif
                        class="flex min-h-11 items-center rounded-control px-3 text-sm font-medium text-body hover:bg-cream hover:text-gold-dark aria-[current=page]:text-gold-dark"
                    >{{ $label }}</a>
                </li>
            @endforeach

            <li class="pt-1 sm:hidden">
                <x-public.portal-link variant="menu" />
            </li>
        </ul>
    </details>

    <ul class="hidden items-center gap-x-5.5 lg:flex">
        @foreach ($links as [$href, $label, $current])
            <li>
                <a
                    href="{{ $href }}"
                    @if ($current) aria-current="page" @endif
                    class="tap-target block text-sm font-medium whitespace-nowrap text-body hover:text-gold-dark aria-[current=page]:text-gold-dark"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
