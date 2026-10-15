{{--
    A topic badge: the small uppercase tag in front of an article's date.

    PROPS. `tone` is `light` (a square-cornered tag on gold at 15%, in the subtitle
    brown, for the white and cream cards) or `dark` (a pill on gold at 18%, in light
    gold, for the navy article header). The slot is the topic, and nothing else: it
    is printed straight after the opening tag, so the markup reads `>Topic</span>`.
    The caller's classes reach the tag through $attributes.
--}}
@props(['tone' => 'light'])

<span {{ $attributes->class([
    'uppercase',
    'rounded-badge bg-gold/15 px-2.25 py-0.75 text-badge text-subtitle' => $tone === 'light',
    'rounded-pill bg-gold/18 px-2.5 py-1 text-badge-pill text-gold-light' => $tone === 'dark',
]) }}>{{ $slot }}</span>
