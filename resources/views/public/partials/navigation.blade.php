@use('App\Support\PublicRoute')
@use('Illuminate\Support\Facades\Route')

{{--
    ONE LIST, RENDERED TWICE. A closed <details> cannot be forced open by CSS
    alone in every browser, and this page runs no script, so the narrow-screen
    menu and the wide-screen row are two elements built from the same list: one
    is hidden at each width. A page added later is ONE entry below, not two
    edits that can disagree. Cross-page links go through PublicRoute, never route().

    Each entry is [url, label], in the order they are
    shown: About, Programmes, Publications, Verify, Contact (P4-T12, P4-T13).

    Every link targets a home section: a fragment on home itself, or the localized
    home's URL plus the fragment on an internal page. No section is marked current:
    the request cannot tell which fragment the visitor has scrolled to.

    Below 1024px the links are the <details> menu: a pill-shaped "Menu" button of
    at least 44px, and a white panel of 44px rows. Below 640px the panel's last item
    is the portal pill, which has no room in the bar there (header.blade.php).
--}}
@php
    $home = Route::is('public.home', 'ar.public.home') ? '' : PublicRoute::url('public.home');
    $links = [
        [$home.'#about', __('public.links.about')],
        [$home.'#programmes', __('public.links.programmes')],
        [$home.'#publications', __('public.links.publications')],
        [$home.'#verify', __('public.nav.verify')],
        [$home.'#contact', __('public.links.contact')],
    ];
@endphp

<nav aria-label="{{ __('public.nav.label') }}" class="ms-auto">
    <details class="group relative lg:hidden">
        <summary class="inline-flex min-h-11 min-w-11 cursor-pointer list-none items-center justify-center rounded-pill border border-navy/20 px-4 text-ui font-semibold text-navy hover:bg-navy/5 group-open:border-navy group-open:bg-navy group-open:text-cream group-open:hover:bg-navy-hover [&::-webkit-details-marker]:hidden">{{ __('public.nav.menu') }}</summary>

        <ul class="absolute end-0 top-full z-20 mt-2 w-60 rounded-field border border-navy/10 bg-card p-2 shadow-card-hover">
            @foreach ($links as [$href, $label])
                <li>
                    <a
                        href="{{ $href }}"
                        class="flex min-h-11 items-center rounded-control px-3 text-sm font-medium text-body hover:bg-cream hover:text-gold-dark"
                    >{{ $label }}</a>
                </li>
            @endforeach

            <li class="pt-1 sm:hidden">
                <x-public.portal-link variant="menu" />
            </li>
        </ul>
    </details>

    <ul class="hidden items-center gap-x-5.5 lg:flex">
        @foreach ($links as [$href, $label])
            <li>
                <a
                    href="{{ $href }}"
                    class="tap-target block text-sm/[1.6] font-medium whitespace-nowrap text-body hover:text-gold-dark"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
