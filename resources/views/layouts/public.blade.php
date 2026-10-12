{{--
    THE STYLESHEET IS LOADED ONLY WHEN IT HAS BEEN BUILT, AND THE GUARD IS
    LOAD-BEARING. CI runs the test suite BEFORE it builds assets
    (.github/workflows/ci.yml), and ExampleTest requests the home page, so an
    unguarded @vite here would throw "Unable to locate file in Vite manifest" in
    a checkout with no build. This is the guard Laravel's own welcome page
    carries. The page is complete HTML without the stylesheet; it is only
    unstyled.

    The condition is computed here, ahead of the markup, rather than written
    inside the @if in the head: LocalizationTest reads Blade for hardcoded prose
    and cannot see through three levels of nested parentheses, so the inline form
    reads to it as a sentence.

    System font stacks only (see resources/css/public.css): no font file, no
    script and no third-party origin is loaded by this layout.
--}}
@php
    $stylesheetBuilt = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="{{ __('public.meta.description') }}">

    @if ($stylesheetBuilt)
        @vite('resources/css/public.css')
    @endif
</head>
<body class="flex min-h-screen flex-col bg-cream-50 font-sans text-navy-900 antialiased">
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-navy-900 focus:px-4 focus:py-2 focus:text-cream-50 focus:outline-gold-400"
    >{{ __('public.skip_to_content') }}</a>

    @include('public.partials.header')

    <main id="main" class="grow">
        @yield('content')
    </main>

    @include('public.partials.footer')
</body>
</html>
