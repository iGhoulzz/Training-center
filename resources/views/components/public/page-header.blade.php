{{--
    The navy band that opens a page other than the home page: the library's header,
    which the design draws once and the About, Programmes, Contact and programme
    pages borrow, since the prototype shows those only as sections of the home page.

    PROPS. `title` is the page's h1, the ONE h1 of the page. `backHref` and
    `backLabel` add the "back" link above it. `eyebrow` adds the small line over the
    heading, `intro` a paragraph under it. `size` is `default` (the 1200px column,
    with the library's spacing and glow) or `narrow` (the 860px column, with the
    article header's). `titleSize` is `page` (32px to 52px, for every page) or
    `article` (30px to 46px, for an article's own header).

    SLOTS. `kicker` replaces the eyebrow with markup of the caller's, which is how the
    article's header puts its topic and date there. The default slot follows the
    heading and the intro: the article's authors line.

    It is a <section>, not a <header>, so it is not the page's banner landmark and the
    layout's header stays the only one. The gold glow is a radial gradient from
    tokens, hidden from assistive technology and clipped by the band.

    CONTRAST. The back link is cream at 60% over the navy and the glow at full alpha:
    4.73:1 in the library's header and 4.80:1 in the article's, so the design's value
    holds. The eyebrow is gold (4.54:1 and 4.62:1), the intro cream at 72%.
--}}
@props(['title', 'backHref' => null, 'backLabel' => null, 'eyebrow' => null, 'intro' => null, 'size' => 'default', 'titleSize' => 'page'])

<section class="relative overflow-hidden bg-navy text-cream">
    <div
        aria-hidden="true"
        @class([
            'absolute inset-0 to-transparent',
            'bg-radial-[circle_at_84%_18%] from-gold/20 to-48%' => $size === 'default',
            'bg-radial-[circle_at_80%_24%] from-gold/19 to-48%' => $size === 'narrow',
        ])
    ></div>

    <x-public.container :size="$size" class="relative {{ $size === 'narrow' ? 'pt-12 pb-14' : 'pt-13 pb-14.5' }}">
        @if ($backHref !== null)
            <a
                href="{{ $backHref }}"
                class="inline-flex items-center gap-1.5 text-compact font-medium text-cream/60 hover:text-gold-light focus-visible:outline-gold-light max-lg:min-h-11"
            >
                <x-public.icon name="arrow-left" :size="13" class="rtl:-scale-x-100" />
                {{ $backLabel }}
            </a>
        @endif

        @isset($kicker)
            {{ $kicker }}
        @elseif ($eyebrow !== null)
            <x-public.eyebrow tone="dark" class="mt-5">{{ $eyebrow }}</x-public.eyebrow>
        @endisset

        <h1 @class([
            'font-display font-normal text-balance',
            'mt-3 text-page' => $titleSize === 'page' && $size === 'default',
            'mt-4 text-page' => $titleSize === 'page' && $size === 'narrow',
            'mt-4 text-article' => $titleSize === 'article',
        ])>{{ $title }}</h1>

        @if ($intro !== null)
            <p class="mt-4 max-w-155 text-base leading-[1.75] text-pretty text-cream/72">{{ $intro }}</p>
        @endif

        {{ $slot }}
    </x-public.container>
</section>
