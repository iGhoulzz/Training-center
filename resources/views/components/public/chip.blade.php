{{--
    A topic filter on the publications library: a pill link with the topic's name and
    how many published articles it holds.

    PROPS. `href` is the filtered library, built by the caller through PublicRoute.
    `name` is the topic (or "All"), `count` the number in it, and `selected` marks
    the topic being viewed: navy with cream text, and `aria-current="true"` so a
    screen reader says which one it is.

    THE COUNT IS SET BACK BY OPACITY, as the design draws it (55%), and that is
    raised on the white chip. 55% of the chip's body colour on white is 2.86:1, below
    the 4.5:1 a small number needs; 74% is the lowest step that reaches it (4.57:1).
    The selected chip keeps the design's 55%, which is 5.19:1 as drawn.

    The label and the count are separated by a real space in the markup, so the link
    reads "Welding 2" to anything that reads text. Below 1024px the chip is a 44px
    box.

    A TOPIC MAY BE AS LONG AS ITS COLUMN, 100 characters, so the chip never refuses
    to wrap: it is at most as wide as its row, and `wrap-anywhere` breaks even an
    unbroken word, which would otherwise widen a phone's page (Codex's review of
    #106).
--}}
@props(['href', 'name', 'count', 'selected' => false])

<a
    href="{{ $href }}"
    @if ($selected) aria-current="true" @endif
    {{ $attributes->class([
        'inline-flex max-w-full items-center gap-1.75 rounded-pill border px-3.75 py-2 text-caption leading-[normal] font-semibold wrap-anywhere max-lg:min-h-11',
        'border-navy bg-navy text-cream' => $selected,
        'border-navy/14 bg-card text-body' => ! $selected,
    ]) }}
>{{ $name }} <span @class(['opacity-55' => $selected, 'opacity-74' => ! $selected])>{{ $count }}</span></a>
