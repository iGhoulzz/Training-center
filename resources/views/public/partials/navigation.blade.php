@use('App\Support\PublicRoute')

{{--
    ONE LIST, RENDERED TWICE. A closed <details> cannot be forced open by CSS
    alone in every browser, and this page runs no script, so the narrow-screen
    menu and the wide-screen row are two elements built from the same list: one
    is hidden at each width. A page added later is ONE entry below, not two
    edits that can disagree. Every link goes through PublicRoute, never route().

    Each entry is [url, label]. T12 adds About, Programmes and Contact here, and
    T13 adds Publications.
--}}
@php
    $links = [
        [PublicRoute::url('verify.certificates.form'), __('public.links.verify_certificate')],
    ];
@endphp

<nav aria-label="{{ __('public.nav.label') }}">
    <details class="relative md:hidden">
        <summary class="cursor-pointer list-none rounded-md border border-navy-800/30 px-3 py-2 text-sm font-medium [&::-webkit-details-marker]:hidden">{{ __('public.nav.menu') }}</summary>

        <ul class="absolute end-0 top-full z-20 mt-2 w-56 rounded-lg border border-cream-200 bg-white p-2 shadow-lg">
            @foreach ($links as [$href, $label])
                <li><a href="{{ $href }}" class="block rounded-md px-3 py-2 hover:bg-cream-100">{{ $label }}</a></li>
            @endforeach
        </ul>
    </details>

    <ul class="hidden items-center gap-6 md:flex">
        @foreach ($links as [$href, $label])
            <li><a href="{{ $href }}" class="rounded-md py-2 text-sm font-medium hover:underline">{{ $label }}</a></li>
        @endforeach
    </ul>
</nav>
