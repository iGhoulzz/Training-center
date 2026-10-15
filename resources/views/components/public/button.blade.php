{{--
    A link drawn as one of the design's buttons. It is always an <a>: the public
    pages hold no form that submits anything, so nothing here submits.

    PROPS. `href` is the destination, already built by the caller through
    PublicRoute. `variant` is the colour scheme and shape:
      - the pills: `primary` (gold, for the one main action on a dark section),
        `ghost` (an outline for a second action on a dark section), `outline` (a
        navy outline that fills on hover, on a light section) and `navy` (solid navy);
      - the squarer ones (T20b): `control` (solid navy, radius 10: a download),
        `control-outline` (a pale outline, radius 10: a second action beside it) and
        `toggle` (white with a pale outline, radius 12: the library's sort).
    `size` is `lg` (a hero button), `md`, `sm`, or `nav` (the portal pill's measure in
    the header), and for the squarer variants `control` (13px), `control-lg` (13.5px),
    `library` (12.5px, radius 9: the library card's PDF link) and `field` (13px with a
    search box's padding). The pills are fully rounded.

    The `nav` size is the design's 42px pill: 11px of padding round a line of
    `normal` height, because the design draws the pill as a <button> with a `font`
    shorthand, which resets the line height. Below 1024px it is 44px, the size of
    a finger (max-lg:min-h-11). The four squarer sizes are the same, since the design
    draws each of them as a <button> too.

    The slot is the label and, when the design has one, an <x-public.icon> beside
    it. The gap between them is the component's: 8px, the design's 9px on a hero
    button, 7px on the library's. A small button gets an invisible 44px hit area
    (tap-target), so it looks as the design draws it. The dark variants carry a gold
    focus ring, because the default navy one would vanish on their background. Callers
    add their own classes through $attributes.
--}}
@props(['href', 'variant' => 'primary', 'size' => 'md'])

<a
    href="{{ $href }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center font-semibold whitespace-nowrap',
        'rounded-pill' => in_array($variant, ['primary', 'ghost', 'outline', 'navy'], true),
        'rounded-control' => in_array($variant, ['control', 'control-outline'], true) && $size !== 'library',
        'rounded-control-sm' => in_array($variant, ['control', 'control-outline'], true) && $size === 'library',
        'rounded-field' => $variant === 'toggle',
        'gap-2' => ! in_array($size, ['lg', 'library'], true),
        'gap-2.25' => $size === 'lg',
        'gap-1.75' => $size === 'library',
        'bg-gold text-ink shadow-gold hover:bg-gold-light focus-visible:outline-gold-light' => $variant === 'primary',
        'border border-cream/30 text-cream hover:bg-cream/10 focus-visible:outline-gold-light' => $variant === 'ghost',
        'border border-navy/20 text-navy hover:bg-navy hover:text-cream' => $variant === 'outline',
        'bg-navy text-cream hover:bg-navy-hover' => in_array($variant, ['navy', 'control'], true),
        'border border-navy/16 text-body hover:border-navy hover:text-navy' => $variant === 'control-outline',
        'border border-navy/16 bg-card text-body hover:border-navy hover:text-navy' => $variant === 'toggle',
        'px-6 py-3.5 text-ui-lg' => $size === 'lg',
        'px-5 py-3 text-ui' => $size === 'md',
        'tap-target px-4 py-2.5 text-caption' => $size === 'sm',
        'px-4.75 py-2.75 text-ui leading-[normal] max-lg:min-h-11' => $size === 'nav',
        'px-4.25 py-2.75 text-compact leading-[normal] max-lg:min-h-11' => $size === 'control',
        'px-4.75 py-3 text-ui leading-[normal] max-lg:min-h-11' => $size === 'control-lg',
        'px-3.75 py-2.5 text-caption leading-[normal] max-lg:min-h-11' => $size === 'library',
        'px-4.25 py-3 text-compact leading-[normal] max-lg:min-h-11' => $size === 'field',
    ]) }}
>{{ $slot }}</a>
