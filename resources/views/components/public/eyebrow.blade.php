{{--
    The small uppercase line that names a section above its heading: "About the
    centre", "Programmes", "Library". The design draws it once, at 12.5px with wide
    tracking, and only its colour changes with the surface.

    PROPS. `tone` is `light` (gold-dark, for the cream and white surfaces, where
    plain gold is never text) or `dark` (gold, for the navy ones). The caller adds
    its own spacing, and a different tracking where the design draws one (the
    article's "More in" line), through $attributes.
--}}
@props(['tone' => 'light'])

<div {{ $attributes->class([
    'text-eyebrow uppercase',
    'text-gold-dark' => $tone === 'light',
    'text-gold' => $tone === 'dark',
]) }}>{{ $slot }}</div>
