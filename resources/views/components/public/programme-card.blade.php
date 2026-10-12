{{--
    One programme: the card the home page and the programmes page both show.

    PROPS. `course` is the Course and `card` is NextUpcomingBatch's entry for it,
    or null when the course has no planned batch. `level` is the heading level of
    the name: 3 under the home page's h2 section (the default), 2 under the
    programmes page's h1. The caller picks it so the headings stay in order.

    WHAT IS SHOWN IS WHAT IS PUBLIC. The price, the start date and the hours come
    from the card, which took them from PricingService and effective_total_hours
    for the batch it chose. With no batch the hours are the course's own, there is
    NO price and NO date, and the call to action is the contact page: a price with
    no batch behind it is a promise the centre has not made. No seats, capacity or
    enrolment count appear anywhere, and nothing here queries.

    NO PHP BLOCK IN THIS TEMPLATE: LocalizationTest reads the body of one as
    untranslated prose, so the values are computed inline.
    The money and date go through translation keys because their ordering and
    separators are localisable; toDecimal() is bare digits.
--}}
@props(['course', 'card' => null, 'level' => 3])

@use('App\Support\PublicRoute')
@use('Carbon\CarbonImmutable')

<article class="flex h-full flex-col rounded-xl border border-cream-200 bg-cream-50 p-6 shadow-sm">
    <h{{ $level }} class="font-serif text-xl font-semibold text-navy-900">
        <a href="{{ PublicRoute::url('public.programmes.show', ['course' => $course->code]) }}" class="hover:underline">{{ $course->name() }}</a>
    </h{{ $level }}>

    @if ($course->description() !== '')
        <p class="mt-3 text-sm text-navy-800">{{ $course->description() }}</p>
    @endif

    <div class="mt-auto pt-5">
        <p class="text-sm font-medium text-navy-700">{{ trans_choice('public.programmes.hours', $card['hours'] ?? $course->total_hours) }}</p>

        @if ($card !== null)
            <p class="mt-1 font-serif text-lg font-semibold text-navy-900">{{ __('public.programmes.price', ['amount' => $card['price']->toDecimal()]) }}</p>
            <p class="mt-1 text-sm text-navy-800">{{ __('public.programmes.starts', ['date' => CarbonImmutable::parse($card['start_date'])->translatedFormat(__('public.programmes.date_format'))]) }}</p>
        @else
            <p class="mt-1 text-sm text-navy-800">{{ __('public.programmes.no_intake') }}</p>
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="mt-3 inline-flex items-center rounded-full border border-navy-800 px-4 py-2 text-sm font-medium text-navy-900 hover:bg-cream-100"
            >{{ __('public.programmes.contact_cta') }}</a>
        @endif
    </div>
</article>
