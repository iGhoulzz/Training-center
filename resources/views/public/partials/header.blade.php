@use('App\Support\PublicContact')
@use('App\Support\PublicRoute')

@php($contact = PublicContact::fromConfig())

<header>
    @if ($contact->email !== '' || $contact->phone !== '')
        <div class="bg-navy-900 text-sm text-cream-100">
            <ul
                aria-label="{{ __('public.header.contact_label') }}"
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2"
            >
                @if ($contact->email !== '')
                    <li><x-public.email-link class="hover:text-gold-400 focus-visible:outline-gold-400" /></li>
                @endif
                @if ($contact->phone !== '')
                    <li><x-public.phone-link class="hover:text-gold-400 focus-visible:outline-gold-400" /></li>
                @endif
            </ul>
        </div>
    @endif

    <div class="border-b border-cream-200 bg-cream-50">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ PublicRoute::url('public.home') }}" class="rounded-md">
                <x-public.brand />
            </a>

            @include('public.partials.navigation')
        </div>
    </div>
</header>
