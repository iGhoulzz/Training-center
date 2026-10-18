{{--
    THE STYLESHEET AND THE FONTS ARE LOADED ONLY WHEN THEY HAVE BEEN BUILT, AND THE
    GUARD IS LOAD-BEARING. CI runs the test suite BEFORE it builds assets
    (.github/workflows/ci.yml), and ExampleTest requests the home page, so an
    unguarded @vite here would throw "Unable to locate file in Vite manifest" in
    a checkout with no build. This is the guard Laravel's own welcome page
    carries. The page is complete HTML without the stylesheet; it is only
    unstyled. The fonts sit inside the same guard, because their manifest is
    written by the same build: Vite::fonts() reads it and fails on a family it
    does not list.

    The condition is computed here, ahead of the markup, rather than written
    inside the @if in the head: LocalizationTest reads Blade for hardcoded prose
    and cannot see through three levels of nested parentheses, so the inline form
    reads to it as a sentence.

    Both fonts are self-hosted (vite.config.js), so no third-party origin is loaded
    by this layout. The title is the centre's full English name, from
    config/public_site.php, unless a page gives its own.

    ONE SCRIPT, ON ONE PAGE. A page that sets the `seal_coin` section (the home
    page) gets resources/js/public-seal.js, the hero seal's coin turn. It is a
    same-origin module from the build, inside the same guard. Every other public
    page loads no script at all.
--}}
@use('Illuminate\Support\Facades\Vite')

@php
    $stylesheetBuilt = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('public_site.brand.name'))</title>
    <meta name="description" content="{{ __('public.meta.description') }}">

    @if ($stylesheetBuilt)
        {{ Vite::fonts(['instrument-serif', 'ibm-plex-sans-arabic']) }}
        @vite('resources/css/public.css')
        @hasSection('seal_coin')
            @vite('resources/js/public-seal.js')
        @endif
    @endif
</head>
<body class="flex min-h-screen flex-col bg-cream font-sans text-ink antialiased">
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-60 focus:rounded-control focus:bg-navy focus:px-4 focus:py-2 focus:text-cream focus:outline-gold-light"
    >{{ __('public.skip_to_content') }}</a>

    @include('public.partials.header')

    <main id="main" class="grow">
        @yield('content')
    </main>

    @include('public.partials.footer')
</body>
</html>
