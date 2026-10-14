{{--
    One programme: the card the home page and the programmes page both show.

    PROPS. `programme` is a PublicProgramme, the closed projection the public
    pages render, never a model. `level` is the heading level of the name: 3 under
    the home page's h2 section (the default), 2 under the programmes page's h1.
    The caller picks it so the headings stay in order.

    The card owns its heading, its description and the contact call to action a
    programme with no intake gets. The facts (hours, price, start date, or the
    no-intake line) are programme-facts, shared with the programme page. No seats,
    capacity or enrolment count appear anywhere, and nothing here queries.
--}}
@props(['programme', 'level' => 3])

@use('App\Support\PublicRoute')

<article class="flex h-full flex-col rounded-card border border-navy/9 bg-cream p-6 shadow-card">
    <h{{ $level }} class="font-display text-xl text-navy">
        <a href="{{ PublicRoute::url('public.programmes.show', ['course' => $programme->id]) }}" class="hover:underline">{{ $programme->name }}</a>
    </h{{ $level }}>

    @if ($programme->description !== '')
        <p class="mt-3 text-sm text-body">{{ $programme->description }}</p>
    @endif

    <div class="mt-auto pt-5">
        <x-public.programme-facts :programme="$programme" />

        @if ($programme->price === null)
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="mt-3 inline-flex items-center rounded-pill border border-navy/20 px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-cream"
            >{{ __('public.programmes.contact_cta') }}</a>
        @endif
    </div>
</article>
