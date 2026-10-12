@use('App\Support\PublicRoute')

@php
    $email = trim((string) config('public_site.contact.email'));
    $phone = trim((string) config('public_site.contact.phone'));
    // The tel: link keeps the digits and a leading plus; the visible text keeps
    // whatever separators the configured value was written with.
    $telephone = (str_starts_with($phone, '+') ? '+' : '').preg_replace('/\D/', '', $phone);
@endphp

<header>
    @if ($email !== '' || $phone !== '')
        <div class="bg-navy-900 text-sm text-cream-100">
            <ul
                aria-label="{{ __('public.header.contact_label') }}"
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2"
            >
                {{-- dir="ltr": an address or a number keeps its order inside right-to-left text. --}}
                @if ($email !== '')
                    <li><a href="mailto:{{ $email }}" dir="ltr" class="hover:text-gold-400 focus-visible:outline-gold-400">{{ $email }}</a></li>
                @endif
                @if ($phone !== '')
                    <li><a href="tel:{{ $telephone }}" dir="ltr" class="hover:text-gold-400 focus-visible:outline-gold-400">{{ $phone }}</a></li>
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
