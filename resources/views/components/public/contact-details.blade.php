{{--
    The centre's contact block, as the design's CONTACT section draws it: on the left
    the eyebrow, the heading and a row for each detail the centre publishes, on the
    right a card with a directions button. The home page's contact section and the
    contact page both render it, so the two cannot disagree about what is shown.

    PROPS. `headingId` is the heading's id, for the section that holds the block to
    label itself by it. The block is an h2 under the page's h1 or the home page's.
    The caller's classes reach the block through $attributes.

    WHAT IS SHOWN IS WHAT THE CENTRE PUBLISHES. It reads PublicContact itself, like the
    header's top bar and the two link components. A row (telephone, email) appears only
    while its value is set. The design's address and opening-hours rows, and every
    row's note, are left out: the centre has sent neither, and the notes name hours and
    services it has not confirmed. The right-hand card shows only while a map link is
    set, and holds only the design's bottom bar: the design's map area would need an
    embedded third-party map, so there is a plain link to the visitor's own map app
    instead (no iframe, no third-party origin). It opens in a new tab with the safe rel,
    and says so for a screen reader.

    The values are the link components, so each is the same link the header prints. They
    are 15px in ink, gold-dark on hover, and a 44px target below 1024px (tap-target: the
    rows are spaced far enough apart for the invisible area).
--}}
@props(['headingId'])

@use('App\Support\PublicContact')

@php($contact = PublicContact::fromConfig())

<div {{ $attributes->class(['flex flex-wrap items-start gap-12']) }}>
    <div class="min-w-0 flex-[1_1_320px]">
        <x-public.section-heading :id="$headingId" :eyebrow="__('public.contact.eyebrow')" :title="__('public.contact.title')" class="mb-5.5" />

        @if ($contact->phone !== '' || $contact->email !== '')
            <ul class="flex flex-col gap-4">
                @if ($contact->phone !== '')
                    <li class="flex items-start gap-3.5">
                        <span aria-hidden="true" class="flex size-9.5 flex-none items-center justify-center rounded-tile-sm bg-navy/6">
                            <x-public.icon name="phone" :size="17" :stroke-width="1.8" class="text-navy" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-row-label text-meta uppercase">{{ __('public.contact.phone_label') }}</span>
                            <span class="mt-0.75 block">
                                <x-public.phone-link class="tap-target inline-flex items-center text-copy font-medium text-ink hover:text-gold-dark" />
                            </span>
                        </span>
                    </li>
                @endif

                @if ($contact->email !== '')
                    <li class="flex items-start gap-3.5">
                        <span aria-hidden="true" class="flex size-9.5 flex-none items-center justify-center rounded-tile-sm bg-navy/6">
                            <x-public.icon name="mail" :size="17" :stroke-width="1.8" class="text-navy" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-row-label text-meta uppercase">{{ __('public.contact.email_label') }}</span>
                            <span class="mt-0.75 block">
                                <x-public.email-link class="tap-target inline-flex items-center text-copy font-medium text-ink hover:text-gold-dark" />
                            </span>
                        </span>
                    </li>
                @endif
            </ul>
        @endif
    </div>

    @if ($contact->mapUrl !== '')
        <div class="min-w-0 flex-[1_1_400px]">
            <x-public.card variant="panel" surface="cream" class="flex flex-wrap items-center gap-3.5 px-5 py-4.5">
                <div class="min-w-45 flex-1">
                    <p class="text-sm font-semibold text-navy">{{ __('public.contact.directions_title') }}</p>
                    <p class="mt-0.5 text-base leading-[1.6] text-meta lg:text-caption">{{ __('public.contact.directions_note') }}</p>
                </div>

                <x-public.button :href="$contact->mapUrl" variant="outline" size="sm" target="_blank" rel="noopener noreferrer">{{ __('public.contact.directions') }}<span class="sr-only"> {{ __('public.contact.directions_new_tab') }}</span></x-public.button>
            </x-public.card>
        </div>
    @endif
</div>
