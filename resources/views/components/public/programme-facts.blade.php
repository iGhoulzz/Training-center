{{--
    A programme's facts, as a list: the hours, and then either the price and the
    start date or the line that says no intake has been announced. Shared by the
    programme card and the programme page, so the two cannot disagree about what a
    programme costs, when it begins, or how a date is written.

    PROPS. `course` is the Course and `card` is NextUpcomingBatch's entry for it,
    or null when the course has no planned batch. The caller's own classes reach
    the list through $attributes.

    WHAT IS SHOWN IS WHAT IS PUBLIC. With a batch the hours, price and start date
    are the card's, which took them from PricingService and effective_total_hours
    for the batch it chose. With none the hours are the course's own, there is NO
    price and NO date, and a price with no batch behind it is a promise the centre
    has not made. No seats, capacity or enrolment count appear here, and nothing
    here queries.

    NO PHP BLOCK IN THIS TEMPLATE: LocalizationTest reads the body of one as
    untranslated prose, so the values are computed inline. The money and date go
    through translation keys because their ordering and separators are
    localisable; toDecimal() is bare digits.
--}}
@props(['course', 'card' => null])

@use('Carbon\CarbonImmutable')

<ul data-programme-facts {{ $attributes->class(['flex flex-col gap-1']) }}>
    <li class="text-sm font-medium text-navy-700">{{ trans_choice('public.programmes.hours', $card['hours'] ?? $course->total_hours) }}</li>

    @if ($card !== null)
        <li class="font-serif text-lg font-semibold text-navy-900">{{ __('public.programmes.price', ['amount' => $card['price']->toDecimal()]) }}</li>
        <li class="text-sm text-navy-800">{{ __('public.programmes.starts', ['date' => CarbonImmutable::parse($card['start_date'])->translatedFormat(__('public.programmes.date_format'))]) }}</li>
    @else
        <li class="text-sm text-navy-800">{{ __('public.programmes.no_intake') }}</li>
    @endif
</ul>
