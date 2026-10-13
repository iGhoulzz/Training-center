{{--
    The page's column: centred, as wide as the design's 1200px at most, with the
    24px gutters on either side that keep text off the screen's edge on a phone.
    `size="narrow"` is the 860px column an article and its header use.

    It holds no content of its own. A caller adds the rest of the section's layout
    (a flex row, vertical padding) through its own classes, which reach the element
    through $attributes.
--}}
@props(['size' => 'default'])

<div {{ $attributes->class([
    'mx-auto w-full px-6',
    'max-w-site' => $size === 'default',
    'max-w-narrow' => $size === 'narrow',
]) }}>{{ $slot }}</div>
