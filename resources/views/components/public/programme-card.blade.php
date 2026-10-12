{{--
    One programme: the card the home page and the programmes page both show.

    PROPS. `course` is the Course and `card` is NextUpcomingBatch's entry for it,
    or null when the course has no planned batch. `level` is the heading level of
    the name: 3 under the home page's h2 section (the default), 2 under the
    programmes page's h1. The caller picks it so the headings stay in order.

    The card owns its heading, its description and the contact call to action a
    course with no batch gets. The facts (hours, price, start date, or the
    no-intake line) are programme-facts, shared with the programme page. No seats,
    capacity or enrolment count appear anywhere, and nothing here queries.
--}}
@props(['course', 'card' => null, 'level' => 3])

@use('App\Support\PublicRoute')

<article class="flex h-full flex-col rounded-xl border border-cream-200 bg-cream-50 p-6 shadow-sm">
    <h{{ $level }} class="font-serif text-xl font-semibold text-navy-900">
        <a href="{{ PublicRoute::url('public.programmes.show', ['course' => $course->getKey()]) }}" class="hover:underline">{{ $course->name() }}</a>
    </h{{ $level }}>

    @if ($course->description() !== '')
        <p class="mt-3 text-sm text-navy-800">{{ $course->description() }}</p>
    @endif

    <div class="mt-auto pt-5">
        <x-public.programme-facts :course="$course" :card="$card" />

        @if ($card === null)
            <a
                href="{{ PublicRoute::url('public.contact') }}"
                class="mt-3 inline-flex items-center rounded-full border border-navy-800 px-4 py-2 text-sm font-medium text-navy-900 hover:bg-cream-100"
            >{{ __('public.programmes.contact_cta') }}</a>
        @endif
    </div>
</article>
