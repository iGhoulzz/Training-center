{{--
    The centre's public email as a mailto: link, or nothing when none is set.

    It reads PublicContact itself, so the header, the home page and the contact
    page cannot disagree about the address or how it is trimmed. Callers pass
    their own classes, which reach the link through $attributes.

    dir="ltr": an email address keeps its order inside right-to-left text.
--}}
@use('App\Support\PublicContact')

@php($contact = PublicContact::fromConfig())

@if ($contact->email !== '')
    <a href="mailto:{{ $contact->email }}" dir="ltr" {{ $attributes }}>{{ $contact->email }}</a>
@endif
