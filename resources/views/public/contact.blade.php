@extends('layouts.public')

{{--
    Contact: links, not a form. A public page holds no session, so a form posted
    from it would carry no CSRF token, and the system has nowhere to send messages.

    The email and telephone are built as the header builds them (PageController
    prepares both). The directions link opens the owner's map in the visitor's own
    map app: a plain link, with NO iframe and NO embed, so no third-party origin
    loads on this page. It shows only while the map URL is set. There is no street
    address and no opening hours until the owner sends them.
--}}
@use('App\Support\PublicRoute')

@section('title')
    {{ __('public.page_title', ['page' => __('public.contact.heading'), 'name' => config('app.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="font-serif text-4xl font-semibold">{{ __('public.contact.heading') }}</h1>
        <p class="mt-4 text-lg text-navy-800">{{ __('public.contact.intro') }}</p>

        {{-- dir="ltr": an email address or a number keeps its order inside right-to-left text. --}}
        <dl class="mt-8 grid gap-6 rounded-xl border border-cream-200 bg-white p-6 sm:grid-cols-2">
            @if ($contact['email'] !== '')
                <div>
                    <dt class="text-sm font-semibold uppercase tracking-wider text-navy-700">{{ __('public.contact.email_label') }}</dt>
                    <dd class="mt-1 text-lg"><a href="mailto:{{ $contact['email'] }}" dir="ltr" class="underline hover:text-navy-700">{{ $contact['email'] }}</a></dd>
                </div>
            @endif

            @if ($contact['phone'] !== '')
                <div>
                    <dt class="text-sm font-semibold uppercase tracking-wider text-navy-700">{{ __('public.contact.phone_label') }}</dt>
                    <dd class="mt-1 text-lg"><a href="tel:{{ $contact['telephone'] }}" dir="ltr" class="underline hover:text-navy-700">{{ $contact['phone'] }}</a></dd>
                </div>
            @endif
        </dl>

        @if ($contact['map_url'] !== '')
            <a
                href="{{ $contact['map_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-8 inline-flex items-center rounded-full bg-navy-900 px-6 py-3 font-medium text-cream-50 hover:bg-navy-800"
            >{{ __('public.contact.directions') }}<span class="sr-only"> {{ __('public.contact.directions_new_tab') }}</span></a>
        @endif
    </div>
@endsection
