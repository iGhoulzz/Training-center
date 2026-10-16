{{--
    One programme: the card the home page and the programmes page both show.

    PROPS. `programme` is a PublicProgramme, the closed projection the public
    pages render, never a model. `level` is the heading level of the name: 3 under
    the home page's h2 section (the default), 2 under the programmes page's h1.
    The caller picks it so the headings stay in order.

    THE CARD AS DESIGNED, MINUS WHAT THE SYSTEM CANNOT SAY. A gold rule runs across
    the top of every card and sweeps in under `motion-safe:`. The hours come first, the
    name is a link to the programme's page, then the description, and under a hairline
    the price with its start date. The design's category tag, Arabic name and seats
    pill are absent: there is no tag data, the projection carries one language, and
    how many places are taken is not public.

    The card owns its heading, its description and the contact call to action a
    programme with no intake gets. The facts are programme-facts, shared with the
    programme page. No seats, capacity or enrolment count appear anywhere, and nothing
    here queries. The description is a sentence, so it is 16px below 1024px and the
    design's 13.5px from there up.
--}}
@props(['programme', 'level' => 3])

@use('App\Support\PublicRoute')

<x-public.card as="article" variant="flat" surface="cream" hover="border" class="relative flex h-full flex-col gap-3.5 overflow-hidden p-6">
    <div aria-hidden="true" class="absolute start-0 top-0 h-[3px] w-full origin-left bg-gold rtl:origin-right motion-safe:animate-sweep"></div>

    <x-public.programme-facts :programme="$programme" part="hours" />

    <h{{ $level }} class="text-card-title font-semibold text-navy">
        <a href="{{ PublicRoute::url('public.programmes.show', ['course' => $programme->id]) }}" class="tap-target block wrap-anywhere hover:underline">{{ $programme->name }}</a>
    </h{{ $level }}>

    @if ($programme->description !== '')
        <p class="flex-1 text-base leading-[1.65] wrap-anywhere text-body-secondary lg:text-ui">{{ $programme->description }}</p>
    @endif

    <div class="mt-auto border-t border-navy/9 pt-3.5">
        <x-public.programme-facts :programme="$programme" part="terms" />

        @if ($programme->price === null)
            <x-public.button :href="PublicRoute::url('public.contact')" variant="outline" size="sm" class="mt-3">{{ __('public.programmes.contact_cta') }}</x-public.button>
        @endif
    </div>
</x-public.card>
