@use('App\Support\PublicRoute')
@use('Illuminate\Support\Facades\Route')

{{--
    ONE LIST, RENDERED TWICE. The narrow-screen
    menu and the wide-screen row are two elements built from the same list: one
    is hidden at each width. A page added later is ONE entry below, not two
    edits that can disagree. Cross-page links go through PublicRoute, never route().

    Each entry is [url, label], in the order they are
    shown: About, Programmes, Publications, Verify, Contact (P4-T12, P4-T13).

    Every link targets a home section: a fragment on home itself, or the localized
    home's URL plus the fragment on an internal page. No section is marked current:
    the request cannot tell which fragment the visitor has scrolled to.

    Below 1024px, :target opens the mobile panel without script. A section link
    changes the target again, closing the panel before its destination scrolls into
    view. The close link targets a zero-size marker in the same sticky header.
    Below 640px the panel's last item is the portal pill (header.blade.php).
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

<nav id="public-navigation" tabindex="-1" aria-label="{{ __('public.nav.label') }}" class="group/nav relative ms-auto">
    <span id="public-navigation-closed" tabindex="-1" class="absolute size-0"></span>
    <div class="relative lg:hidden">
        <a href="#public-navigation" aria-controls="public-mobile-menu" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-pill border border-navy/20 px-4 text-ui font-semibold text-navy hover:bg-navy/5 group-target/nav:hidden">{{ __('public.nav.menu') }}</a>
        <a href="#public-navigation-closed" aria-controls="public-mobile-menu" class="hidden min-h-11 min-w-11 items-center justify-center rounded-pill border border-navy px-4 text-ui font-semibold text-cream bg-navy hover:bg-navy-hover group-target/nav:inline-flex">{{ __('public.nav.close_menu') }}</a>

        <ul id="public-mobile-menu" class="absolute end-0 top-full z-20 mt-2 hidden w-60 rounded-field border border-navy/10 bg-card p-2 shadow-card-hover group-target/nav:block">
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
    </div>

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
