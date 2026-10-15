{{--
    A programme's facts, as a list: the hours, and then either the price and the
    start date or the line that says no intake has been announced. Shared by the
    programme card and the programme page, so the two cannot disagree about what a
    programme costs, when it begins, or how a date is written.

    PROPS. `programme` is a PublicProgramme, the closed projection the public
    pages render, never a model. `part` is which of the facts this list holds:
    `all` (the default: the programme page's whole list), `hours` (only the hours, the
    line the design puts at the top of a card) or `terms` (the price and start date,
    or the no-intake line: the foot of a card). A card renders the two parts apart,
    because the design separates them with its name and description, and a test reads
    the card's two lists together and compares them with the page's one. The caller's
    own classes reach the list through $attributes.

    WHAT IS SHOWN IS WHAT IS PUBLIC. With an intake the price and start date are
    the planned batch's, and the hours its effective hours. With none the hours
    are the course's own, there is NO price and NO date (both are null on the
    projection), and a price with no batch behind it is a promise the centre has not
    made. No seats, capacity or enrolment count appear here, and nothing here
    queries.

    THE PRICE IS PRINTED AS IT IS HELD, and then its currency: the amount is already
    bare digits, never reformatted here, and "LYD" is its own smaller span in the
    sans face, set off by a real space and not a margin, as the design draws it.

    NO PHP BLOCK IN THIS TEMPLATE: LocalizationTest reads the body of one as
    untranslated prose, so the values are computed inline. The date goes through a
    translation key because its ordering and separators are localisable.
--}}
@props(['programme', 'part' => 'all'])

@use('Carbon\CarbonImmutable')

<ul data-programme-facts {{ $attributes->class(['flex flex-col']) }}>
    @if ($part !== 'terms')
        <li class="text-small text-meta">{{ trans_choice('public.programmes.hours', $programme->hours) }}</li>
    @endif

    @if ($part !== 'hours')
        @if ($programme->price !== null)
            <li class="font-display text-price text-navy">{{ $programme->price }} <span class="font-sans text-small text-meta">{{ __('public.programmes.currency') }}</span></li>
            <li class="mt-0.5 text-note text-meta">{{ __('public.programmes.starts', ['date' => CarbonImmutable::parse($programme->startDate)->translatedFormat(__('public.programmes.date_format'))]) }}</li>
        @else
            <li class="text-base leading-[1.6] text-body lg:text-ui">{{ __('public.programmes.no_intake') }}</li>
        @endif
    @endif
</ul>
