{{--
    A link drawn as one of the design's buttons. It is always an <a>: the public
    pages hold no form, so nothing here submits anything.

    PROPS. `href` is the destination, already built by the caller through
    PublicRoute. `variant` is the colour scheme: `primary` (gold, for the one main
    action on a dark section), `ghost` (an outline for a second action on a dark
    section), `outline` (a navy outline that fills on hover, on a light section)
    and `navy` (solid navy). `size` is `lg` (a hero button), `md`, `sm`, or `nav`
    (the portal pill's measure in the header). All are fully rounded.

    The slot is the label and, when the design has one, an <x-public.icon> beside
    it. The gap between them is the component's. A small button gets an invisible
    44px hit area (tap-target), so it looks as the design draws it. The two dark
    variants carry a gold focus ring, because the default navy one would vanish on
    their background. Callers add their own classes through $attributes.
--}}
@props(['href', 'variant' => 'primary', 'size' => 'md'])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 rounded-pill font-semibold whitespace-nowrap',
        'bg-gold text-ink shadow-gold hover:bg-gold-light focus-visible:outline-gold-light' => $variant === 'primary',
        'border border-cream/30 text-cream hover:bg-cream/10 focus-visible:outline-gold-light' => $variant === 'ghost',
        'border border-navy/20 text-navy hover:bg-navy hover:text-cream' => $variant === 'outline',
        'bg-navy text-cream hover:bg-navy-hover' => $variant === 'navy',
        'px-6 py-3.5 text-ui-lg' => $size === 'lg',
        'px-5 py-3 text-ui' => $size === 'md',
        'tap-target px-4 py-2.5 text-caption' => $size === 'sm',
        'min-h-11 px-4.75 py-2.75 text-ui' => $size === 'nav',
    ]) }}
>{{ $slot }}</a>
