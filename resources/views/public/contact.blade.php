@extends('layouts.public')

{{--
    Contact: links, not a form. A public page holds no session, so a form posted
    from it would carry no CSRF token, and the system has nowhere to send messages.

    The prototype shows Contact only as a section of the home page, so this page takes
    the library's navy header (with the page's introduction) and then that section's
    body: the same contact-details block the home page renders. It reads PublicContact
    like the header does, shows a row only for a value that is set, and shows the
    directions card only while a map link is set. The directions link opens the owner's
    map in the visitor's own map app: a plain link, with NO iframe and NO embed, so no
    third-party origin loads on this page. There is no street address and no opening
    hours until the owner sends them.
--}}
@use('App\Support\PublicContact')

@php($contact = PublicContact::fromConfig())

@section('title')
    <x-public.page-title :page="__('public.contact.heading')" />
@endsection

@section('content')
    <x-public.page-header :title="__('public.contact.heading')" :intro="__('public.contact.intro')" />

    @if ($contact->email !== '' || $contact->phone !== '' || $contact->mapUrl !== '')
        <section aria-labelledby="contact-heading" class="bg-card">
            <x-public.container class="py-20">
                <x-public.contact-details heading-id="contact-heading" />
            </x-public.container>
        </section>
    @endif
@endsection
