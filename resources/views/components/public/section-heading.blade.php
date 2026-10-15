{{--
    A section's heading block: the eyebrow, the heading and, when the section has
    one, a short introduction.

    PROPS. `title` is the heading text and `level` its heading level (2 by default;
    the caller picks it so the page's headings stay in order). `id` goes on the
    heading, for the section's aria-labelledby. `eyebrow` and `intro` are optional.
    `tone` is `light` (navy heading, gold-dark eyebrow) or `dark` (cream heading, gold
    eyebrow) for the surface it sits on. `size` is `default` (the design's H2: 28px to
    40px) or `lg` (the home About section's: 29px to 42px, tighter, with a 14px gap
    under the eyebrow where the others have 12px). `balance` evens the heading's lines
    where the design asks for it.

    The introduction is a sentence, so it is 16px below 1024px and the design's 15px
    from there up. The caller's classes (the block's width, its margin) reach the
    wrapper through $attributes.
--}}
@props(['title', 'eyebrow' => null, 'intro' => null, 'id' => null, 'level' => 2, 'tone' => 'light', 'size' => 'default', 'balance' => false])

<div {{ $attributes }}>
    @if ($eyebrow !== null)
        <x-public.eyebrow :tone="$tone">{{ $eyebrow }}</x-public.eyebrow>
    @endif

    <h{{ $level }}
        @if ($id !== null) id="{{ $id }}" @endif
        @class([
            'font-display font-normal',
            'text-heading mt-3' => $size === 'default',
            'text-heading-lg mt-3.5' => $size === 'lg',
            'text-navy' => $tone === 'light',
            'text-cream' => $tone === 'dark',
            'text-balance' => $balance,
        ])
    >{{ $title }}</h{{ $level }}>

    @if ($intro !== null)
        <p class="mt-3 max-w-150 text-base leading-[1.7] text-pretty text-body-secondary lg:text-copy">{{ $intro }}</p>
    @endif
</div>
