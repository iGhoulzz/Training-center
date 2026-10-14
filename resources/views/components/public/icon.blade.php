{{--
    A named icon, drawn inline: the design's own SVG paths, so no icon package and
    no request. The shapes are decoration, so the icon is hidden from assistive
    technology and takes no focus; whatever it sits beside carries the meaning.

    PROPS. `name` is one of the cases below, and a name that is not here throws,
    so a typo fails the first test that renders the page and not silently as an
    empty box. `size` is the width and height in pixels (the design uses 13 to 21).
    `stroke` is the line weight (the design draws 1.6 to 2.2).

    `linecap` and `linejoin` are the shape of the stroke's ends and corners. The
    design sets them USE BY USE, not icon by icon (the graduation cap is round and
    round as a pillar's icon but round and mitred on the portal pill), so they are
    props, and the defaults are the commonest pair:
      - round and round (the default): the about pillars (award, bar-chart,
        graduation-cap), the contact rows (pin, phone, mail, clock) and the document;
      - round caps, mitred joins (`linejoin="miter"`): the portal pill's graduation
        cap, the arrow and the download;
      - plain ends and mitred joins (`linecap="butt" linejoin="miter"`): the top
        bar's phone and mail, and the search.

    The colour is currentColor, so a caller colours the icon with a token class
    (`text-gold`), which also lets a hover on the parent recolour it. Callers add
    their own classes through $attributes.
--}}
@props(['name', 'size' => 16, 'stroke' => 2, 'linecap' => 'round', 'linejoin' => 'round'])

<svg
    xmlns="http://www.w3.org/2000/svg"
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="{{ $stroke }}"
    stroke-linecap="{{ $linecap }}"
    stroke-linejoin="{{ $linejoin }}"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class(['shrink-0']) }}
>
    @switch($name)
        @case('phone')
            <path d="M4 5h5l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v5a15 15 0 0 1-16-16Z" />
            @break
        @case('mail')
            <path d="M3 6h18v12H3z" />
            <path d="m3 7 9 6 9-6" />
            @break
        @case('graduation-cap')
            <path d="M12 3 3 7.5l9 4.5 9-4.5L12 3Z" />
            <path d="M7 11v4.5c0 1.8 2.6 3.2 5 3.2s5-1.4 5-3.2V11" />
            @break
        @case('arrow-right')
            <path d="M5 12h14m-6-6 6 6-6 6" />
            @break
        @case('download')
            <path d="M12 3v12m0 0-4.5-4.5M12 15l4.5-4.5M4 20h16" />
            @break
        @case('document')
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />
            <path d="M14 3v5h5" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
            @break
        @case('pin')
            <path d="M12 21s7-6.4 7-11a7 7 0 1 0-14 0c0 4.6 7 11 7 11Zm0-8.6a2.4 2.4 0 1 0 0-4.8 2.4 2.4 0 0 0 0 4.8Z" />
            @break
        @case('clock')
            <path d="M12 8v4l3 2m-3 7a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z" />
            @break
        @case('award')
            <path d="M12 4a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm-3 9-1 7 4-2 4 2-1-7" />
            @break
        @case('bar-chart')
            <path d="M4 20V9m5 11V4m5 16v-7m5 7V7" />
            @break
        @default
            @php(throw new InvalidArgumentException("The icon [{$name}] does not exist."))
    @endswitch
</svg>
