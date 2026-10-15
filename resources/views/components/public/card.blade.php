{{--
    A raised surface: the white or cream box the design puts a programme, an article,
    a pillar, a filing record or the directions bar in.

    PROPS. `as` is the element (an <article> for a programme or an article, a
    <section> for a labelled region, a <div> otherwise). `variant` is the box's
    shape, because the design measures each a little differently: `default` (radius
    18, the card shadow: a library card, a teaser entry), `flat` (radius 18 and no
    shadow: a programme card), `pillar`
    (radius 16, the smaller shadow), `box` (radius 16, a slightly stronger hairline:
    the article's filing record and a programme's facts) and `panel` (radius 20, the
    large shadow, clipped to its corners: the directions card). `surface` is `white`
    or `cream`. `hover` is `lift` (a gold hairline and the raised shadow, for a card
    that links on) or `border` (the hairline alone), or false for a card that does
    nothing.

    Padding, layout and the like are the caller's, through $attributes. The card
    holds nothing of its own and no logic.
--}}
@props(['as' => 'div', 'variant' => 'default', 'surface' => 'white', 'hover' => false])

<{{ $as }} {{ $attributes->class([
    'border',
    'rounded-card border-navy/9 shadow-card' => $variant === 'default',
    'rounded-card border-navy/9' => $variant === 'flat',
    'rounded-card-sm border-navy/9 shadow-card-sm' => $variant === 'pillar',
    'rounded-card-sm border-navy/10 shadow-card' => $variant === 'box',
    'overflow-hidden rounded-panel border-navy/12 shadow-panel' => $variant === 'panel',
    'bg-card' => $surface === 'white',
    'bg-cream' => $surface === 'cream',
    'hover:border-gold/55 hover:shadow-card-hover' => $hover === 'lift',
    'hover:border-gold/60' => $hover === 'border',
]) }}>{{ $slot }}</{{ $as }}>
