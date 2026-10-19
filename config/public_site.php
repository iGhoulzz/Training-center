<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public site's brand and published contact details (phase 4, P4-T11, T20a)
|--------------------------------------------------------------------------
|
| What the centre prints for the public to reach it. The values below are the
| centre's real public email and telephone, committed as defaults by the
| owner's decision: they are what the site publishes anyway, so a public
| repository reveals nothing more. Each can still be overridden from `.env`
| (see the commented keys in .env.example) without a code change.
|
| NO BLADE FILE HOLDS THEM. The header's top bar reads these keys, and so will
| the contact page and the home page's contact section (P4-T12). The footer shows
| no contact details, as the design draws it. Changing a number is one edit, and
| the views cannot drift from each other.
|
| `map_url` defaults to the owner's map link (P4-T12), given as a share link with
| its `?g_st=ipc` tracking parameter dropped. The contact page renders it as a
| plain "Get directions" link and embeds nothing, so no third-party origin loads
| on the page. It is rendered only while it is set: a blank value in `.env` turns
| the link off.
|
| `brand` is the centre's name as its logo states it (owner, 2026-10-13): plain
| values with no env() behind them, because a proper noun is not a deployment
| setting. They are config and not lang because they are names, not sentences, and
| the Arabic name is the same text on every page. Every public view reads the name
| from here and never from `app.name`, which is the short display name the rest of
| the application uses (APP_NAME). The `logo` is a path under public/, resolved with
| asset(). `short_name` and `tagline` are shown in the header; `name` is the page
| title and the accessible name of the home link; `name_ar` is Arabic, so a view
| that prints it marks it `lang="ar" dir="rtl"`.
*/

return [
    'brand' => [
        'name' => 'Arab Specialist Center for Legal Studies and Training',
        'short_name' => 'A.S.C.L.S.T',
        'tagline' => 'Legal Studies & Training',
        'name_ar' => 'المركز العربي التخصصي للدراسات القانونية والتدريب',
        'logo' => 'images/brand/asclst-logo.jpg',
        // The cut-out seal, a transparent PNG, that turns like a coin in the home
        // page's hero (owner, 2026-10-17). Until the file exists, the hero shows
        // `logo` in its circle instead.
        'seal' => 'images/brand/asclst-seal.png',
    ],

    'contact' => [
        'email' => env('PUBLIC_CONTACT_EMAIL', 'info@aaclat.ly'),
        'phone' => env('PUBLIC_CONTACT_PHONE', '+218-928421304'),
        'map_url' => env('PUBLIC_CONTACT_MAP_URL', 'https://maps.app.goo.gl/nrs5mzbxjh9A7wci8'),
    ],
];
