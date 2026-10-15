{{--
    One of the About section's feature cards: a gold icon tile, a title and a line of
    text. Only the claim the system makes true is drawn (verifiable certification);
    the design's other two are left out until the centre confirms them.

    PROPS. `icon` is an <x-public.icon> name, `title` the heading, `level` its heading
    level (3 under the home page's h2, 2 under a page's h1). The slot is the text.
    The text is a sentence, so it is 16px below 1024px and the design's 13.5px from
    there up.
--}}
@props(['icon', 'title', 'level' => 3])

<x-public.card variant="pillar" {{ $attributes->class(['flex gap-3.75 p-5']) }}>
    <div aria-hidden="true" class="flex size-10.5 flex-none items-center justify-center rounded-tile bg-gold/14">
        <x-public.icon :name="$icon" :size="20" :stroke-width="1.7" class="text-gold-dark" />
    </div>
    <div class="min-w-0 flex-1">
        <h{{ $level }} class="text-copy font-semibold text-navy">{{ $title }}</h{{ $level }}>
        <p class="mt-1 text-base leading-[1.6] text-body-secondary lg:text-ui">{{ $slot }}</p>
    </div>
</x-public.card>
