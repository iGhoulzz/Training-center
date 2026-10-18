{{--
    A PAGE'S <title>: the page's own name, then the centre's, or the centre's alone when
    no page name is given. It is the one place a title is composed, so the layout and
    every page agree on it.

    THE CENTRE'S NAME IS IN THE PAGE'S LANGUAGE (P4-T18a): config('public_site.brand.name')
    on an English page, `name_ar` on an Arabic one. The words around it come from
    `public.page_title`, a key rather than two strings joined here, so a translator can
    reorder them. `page` is printed escaped, as every echo is.
--}}
@props(['page' => null])

@php
    $brandName = config('public_site.brand.'.(str_starts_with(app()->getLocale(), 'ar') ? 'name_ar' : 'name'));
@endphp
{{ $page === null ? $brandName : __('public.page_title', ['page' => $page, 'name' => $brandName]) }}