{{--
    The centre's public telephone as a tel: link, or nothing when none is set.

    The link keeps only the digits and a leading plus (PublicContact decides),
    while the visible text keeps whatever separators the configured value was
    written with. Callers pass their own classes, which reach the link through
    $attributes.

    dir="ltr": a number keeps its order inside right-to-left text.
--}}
@use('App\Support\PublicContact')

@php($contact = PublicContact::fromConfig())

@if ($contact->phone !== '')
    <a href="{{ $contact->telephoneHref }}" dir="ltr" {{ $attributes }}>{{ $contact->phone }}</a>
@endif
