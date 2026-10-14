{{--
    A programme's facts, as a list: the hours, and then either the price and the
    start date or the line that says no intake has been announced. Shared by the
    programme card and the programme page, so the two cannot disagree about what a
    programme costs, when it begins, or how a date is written.

    PROPS. `programme` is a PublicProgramme, the closed projection the public
    pages render, never a model. The caller's own classes reach the list through
    $attributes.

    WHAT IS SHOWN IS WHAT IS PUBLIC. With an intake the price and start date are
    the planned batch's, and the hours its effective hours. With none the hours
    are the course's own, there is NO price and NO date (both are null on the
    projection), and a price with no batch behind it is a promise the centre has
    not made. No seats, capacity or enrolment count appear here, and nothing here
    queries.

    NO PHP BLOCK IN THIS TEMPLATE: LocalizationTest reads the body of one as
    untranslated prose, so the values are computed inline. The money and date go
    through translation keys because their ordering and separators are
    localisable; the price is already bare digits.
--}}
@props(['programme'])

@use('Carbon\CarbonImmutable')

<ul data-programme-facts {{ $attributes->class(['flex flex-col gap-1']) }}>
    <li class="text-sm font-medium text-meta">{{ trans_choice('public.programmes.hours', $programme->hours) }}</li>

    @if ($programme->price !== null)
        <li class="font-display text-lg text-navy">{{ __('public.programmes.price', ['amount' => $programme->price]) }}</li>
        <li class="text-sm text-body">{{ __('public.programmes.starts', ['date' => CarbonImmutable::parse($programme->startDate)->translatedFormat(__('public.programmes.date_format'))]) }}</li>
    @else
        <li class="text-sm text-body">{{ __('public.programmes.no_intake') }}</li>
    @endif
</ul>
