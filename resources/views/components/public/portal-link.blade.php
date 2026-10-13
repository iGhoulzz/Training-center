{{--
    The way into the student portal from the public site (P4-T15): a link to the
    student panel's own sign-in page. It is not a form. A public page holds no
    session, so a form posted from it would carry no CSRF token, and a second
    sign-in path is exactly what the portal should not grow.

    THE ONE PLACE THE PORTAL'S URL IS BUILT. It asks the student panel for its
    login URL, so a change to the panel's path or its login page needs no edit
    here, and the public views never name a route (PublicRouteTest forbids a bare
    route call in them). When the panel is given an Arabic sign-in (T18), the
    locale is added here, once, for all three forms. A panel with no login page
    renders nothing rather than a dead link.

    PROPS. `variant` is the form: `pill` (the header's solid button, with the
    seal's graduation cap), `menu` (the same, across the narrow menu's width, as
    that menu's last item) or `footer` (a plain link, whose classes the footer
    supplies so it sits in its list like the others). Callers add their own
    classes through $attributes.
--}}
@props(['variant' => 'pill'])

@php($href = filament()->getPanel('student')->getLoginUrl())

@if ($href !== null)
    @if ($variant === 'footer')
        <a href="{{ $href }}" {{ $attributes }}>{{ __('public.portal.link') }}</a>
    @else
        <x-public.button :href="$href" variant="navy" size="nav" {{ $attributes->class(['shadow-pill', 'w-full' => $variant === 'menu']) }}>
            <x-public.icon name="graduation-cap" :size="14" linejoin="miter" class="text-gold" />
            {{ __('public.portal.link') }}
        </x-public.button>
    @endif
@endif
