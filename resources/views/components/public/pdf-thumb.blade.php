{{--
    The document thumbnail: a navy portrait tile with a gold page icon over the
    letters PDF, standing in for a preview the system does not generate.

    PROPS. `size` is `lg` (64 by 80, on the home teaser) or `sm` (52 by 66, on the
    article page's filing record). The tile is decoration, so it is hidden from
    assistive technology; the link beside it names the document.

    The label is full gold, not the design's 90%: at 90% it is 4.24:1 on the
    gradient's lighter end, below the 4.5:1 a small label needs, and at full gold it
    is 4.90:1.
--}}
@props(['size' => 'lg'])

<div aria-hidden="true" {{ $attributes->class([
    'flex flex-none flex-col items-center justify-center bg-linear-160 from-navy-hover to-navy rtl:bg-linear-200',
    'h-20 w-16 gap-1.25 rounded-thumb shadow-thumb' => $size === 'lg',
    'h-16.5 w-13 gap-1 rounded-thumb-sm' => $size === 'sm',
]) }}>
    <x-public.icon name="document" :size="$size === 'lg' ? 21 : 18" :stroke-width="1.6" class="text-gold" />
    <span @class(['text-gold', 'text-thumb' => $size === 'lg', 'text-thumb-sm' => $size === 'sm'])>{{ __('publications.public.pdf') }}</span>
</div>
