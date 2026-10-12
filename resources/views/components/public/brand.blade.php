{{--
    The brand lockup: the decorative mark beside the centre's name. The header
    wraps it in the link to the home page and the footer shows it as it is, so
    it takes no props: the two places do not differ.

    The mark is aria-hidden, so a screen reader hears the centre's name once
    rather than "TC" and then the name.
--}}
<span class="flex items-center gap-3">
    <span
        aria-hidden="true"
        class="grid size-10 place-items-center rounded-full bg-navy-800 font-serif text-lg text-gold-400 ring-1 ring-gold-500"
    >{{ __('public.brand.mark') }}</span>
    <span class="font-serif text-lg font-semibold">{{ config('app.name') }}</span>
</span>
