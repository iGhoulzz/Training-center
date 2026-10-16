{{--
    A topic badge: the small uppercase tag in front of an article's date.

    PROPS. `tone` is `light` (a square-cornered tag on gold at 15%, in the subtitle
    brown, for the white and cream cards) or `dark` (a pill on gold at 18%, in light
    gold, for the navy article header). The slot is the topic, and nothing else: it
    is printed straight after the opening tag, so the markup reads `>Topic</span>`.
    The caller's classes reach the tag through $attributes.

    A topic may be as long as its column, 100 characters, so the badge is at most as
    wide as its row and `wrap-anywhere` breaks even an unbroken word. Otherwise it
    widens a phone's page on a card, and is cut off by the navy header's clipping on
    the article page (Codex's review of #106).
--}}
@props(['tone' => 'light'])

<span {{ $attributes->class([
    'max-w-full uppercase wrap-anywhere',
    'rounded-badge bg-gold/15 px-2.25 py-0.75 text-badge text-subtitle' => $tone === 'light',
    'rounded-pill bg-gold/18 px-2.5 py-1 text-badge-pill text-gold-light' => $tone === 'dark',
]) }}>{{ $slot }}</span>
