@extends('layouts.public')

{{--
    Contact: links, not a form. A public page holds no session, so a form posted
    from it would carry no CSRF token, and the system has nowhere to send messages.

    The email and telephone are the email-link and phone-link components, which
    read PublicContact like the header does. The directions link opens the owner's
    map in the visitor's own map app: a plain link, with NO iframe and NO embed, so
    no third-party origin loads on this page. It shows only while PublicContact
    holds a map URL, and that is always an http(s) one. There is no street address
    and no opening hours until the owner sends them.
--}}
@use('App\Support\PublicContact')
@use('App\Support\PublicRoute')

@php($contact = PublicContact::fromConfig())

@section('title')
    {{ __('public.page_title', ['page' => __('public.contact.heading'), 'name' => config('public_site.brand.name')]) }}
@endsection

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="font-display text-4xl text-navy">{{ __('public.contact.heading') }}</h1>
        <p class="mt-4 text-lg text-body">{{ __('public.contact.intro') }}</p>

        @if ($contact->email !== '' || $contact->phone !== '')
            <dl class="mt-8 grid gap-6 rounded-card border border-navy/9 bg-card p-6 sm:grid-cols-2">
                @if ($contact->email !== '')
                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wider text-meta">{{ __('public.contact.email_label') }}</dt>
                        <dd class="mt-1 text-lg"><x-public.email-link class="underline hover:text-gold-dark" /></dd>
                    </div>
                @endif

                @if ($contact->phone !== '')
                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wider text-meta">{{ __('public.contact.phone_label') }}</dt>
                        <dd class="mt-1 text-lg"><x-public.phone-link class="underline hover:text-gold-dark" /></dd>
                    </div>
                @endif
            </dl>
        @endif

        @if ($contact->mapUrl !== '')
            <a
                href="{{ $contact->mapUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-8 inline-flex items-center rounded-pill bg-navy px-6 py-3 font-medium text-cream hover:bg-navy-hover"
            >{{ __('public.contact.directions') }}<span class="sr-only"> {{ __('public.contact.directions_new_tab') }}</span></a>
        @endif
    </div>
@endsection
