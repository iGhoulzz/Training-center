<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public site's published contact details (phase 4, P4-T11)
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
| `map_url` has no default. It stays null until the owner supplies the link, and
| the contact section (P4-T12) renders it only when it is set.
*/

return [
    'contact' => [
        'email' => env('PUBLIC_CONTACT_EMAIL', 'info@aaclat.ly'),
        'phone' => env('PUBLIC_CONTACT_PHONE', '+218-928421304'),
        'map_url' => env('PUBLIC_CONTACT_MAP_URL'),
    ],
];
