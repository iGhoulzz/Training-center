<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public site (phase 4, P4-T11)
|--------------------------------------------------------------------------
|
| Every string on the public layout, its header, navigation and footer, and the
| public pages: home, about, programmes and contact (P4-T12). The groups named
| after those pages hold DRAFT COPY the owner will replace; see each group.
|
| Whole sentences, never fragments assembled in code: the copyright line and the
| home heading carry named placeholders so a translator can reorder them. The
| centre's name, in English and in Arabic, and its contact details are in
| config/public_site.php (proper nouns, not sentences), so none of them is a
| string here.
*/

return [
    'meta' => [
        'description' => 'Courses and certificates from the training centre, with a public register where any certificate can be checked.',
    ],

    'skip_to_content' => 'Skip to the main content',

    'header' => [
        'contact_label' => 'Contact details',
    ],

    'nav' => [
        'label' => 'Main navigation',
        'menu' => 'Menu',
        // The design's short label for the verifier in the navigation row. The
        // footer and the pages say "Verify a certificate" (links.verify_certificate).
        'verify' => 'Verify',
    ],

    /*
    | The student portal's entry on the public site (P4-T15, folded into T20a). A
    | link to the student panel's own login page, never a form: a public page holds
    | no session, so a form posted from it would carry no CSRF token. The note is the
    | design's own sign-in copy and says why a student may have no login.
    */
    'portal' => [
        'link' => 'Student portal',
        'note' => 'No login yet? The registrar issues one when you enrol — a portal account needs an email on your student record.',
    ],

    // A page's <title>: the page's own name, then the centre's. A key rather than
    // two strings joined in code, so a translator can reorder them.
    'page_title' => ':page | :name',

    'links' => [
        'about' => 'About',
        'programmes' => 'Programmes',
        'publications' => 'Publications',
        'verify_certificate' => 'Verify a certificate',
        'contact' => 'Contact',
    ],

    'footer' => [
        'description' => 'Training courses, with certificates anyone can check in the public register.',
        'centre_heading' => 'Centre',
        'services_heading' => 'Services',
        'copyright' => '© :year :name',
    ],

    /*
    | Draft copy pending the owner's text (2026-10-12): replace freely; keep the keys.
    |
    | Home. The page is the design's: the hero, About, Programmes, the verifier's box,
    | the publications teaser and Contact. It states only what the system makes true:
    | certificates are recorded in a public register that anyone can check. The About
    | title and the verifier's and teaser's wording are the design's own, and still
    | draft copy for the owner to confirm.
    */
    'home' => [
        // The heading is the centre's name; the phrase in `heading_emphasis` is set in
        // italic gold inside it, as the design draws it, wherever it appears in the
        // translated name. A name that does not contain it is printed plainly.
        'heading' => ':name',
        'heading_emphasis' => 'Legal Studies',
        'lead' => 'Professional and legal training, with certificates that anyone can check in a public register.',
        'programmes_link' => 'Browse programmes',

        'about_heading' => 'About the centre',
        'about_title' => 'Legal education that answers to practice, not to theory',
        'about_body' => 'The centre runs professional and legal training programmes. Every certificate it issues is entered in a public register, so anyone can confirm it without asking the centre.',
        'about_register' => 'Certificates issued by the centre are recorded in a public register. Any employer, court or bar association can confirm one in seconds using the verifier on this page.',

        'programmes_title' => 'Open for enrolment',
        'request_seat' => 'Request a seat',

        'verify_eyebrow' => 'Public register',
        'verify_heading' => 'Verify a certificate',
        'verify_lead' => 'Enter the reference printed on the certificate. The register returns the holder, the programme and the issue date — and tells you plainly if a certificate has been replaced or revoked.',
        'verify_label' => 'Certificate reference',
        // The real reference format: TC, the year, and eight characters.
        'verify_placeholder' => 'TC-2026-XXXXXXXX',
        'verify_submit' => 'Verify',
        'verify_note' => 'Nothing is stored from a lookup. The register only ever returns what is printed on the certificate itself.',

        'publications_title' => 'Research & legal commentary',
        'publications_intro' => 'Published by the centre and downloadable as the original PDF. Every entry carries its issue date.',
        'publications_all' => 'All publications',
    ],

    /*
    | Draft copy pending the owner's text (2026-10-12): replace freely; keep the keys.
    |
    | About. The design's three feature cards are kept only where the system makes
    | them true: verifiable certification is, and the other two made claims about
    | faculty and teaching format that the centre has not confirmed.
    */
    'about' => [
        'heading' => 'About the centre',
        'intro' => 'The centre runs professional and legal training programmes. Each programme is listed on the programmes page, with its hours and, whenever an intake is planned, its start date and price.',
        'register' => 'Every certificate the centre issues is recorded in a public register. Anyone holding the reference printed on a certificate can check it with the verifier, so nobody has to ask the centre to confirm it.',
        'questions' => 'To ask about a programme or an intake, write to the centre or call it.',

        'certification_heading' => 'Verifiable certification',
        'certification_body' => 'Every certificate is entered in a register that anyone can check online.',

        'programmes_link' => 'See the programmes',
        'contact_link' => 'Contact the centre',
    ],

    /*
    | Draft copy pending the owner's text (2026-10-12): replace freely; keep the keys.
    |
    | Programmes. The card and the programme page show only what is public: the
    | name, the description, the hours and, when an intake is planned, its price and
    | start date. A course with no planned intake shows neither a date nor a price,
    | because a price with no intake behind it is a promise the centre has not made.
    | There are no seats or places anywhere: how many are taken is not public.
    */
    'programmes' => [
        'heading' => 'Programmes',
        'intro' => 'The courses the centre currently offers. A course with an intake planned shows when it begins and what it costs.',
        'empty' => 'No programmes are listed at the moment. Please contact the centre to ask what is coming.',

        // Whole phrases with named placeholders, so a translator can reorder them.
        // The date format is a PHP date() pattern, rendered in the reader's locale.
        // The currency is its own word because the design sets it in a smaller span
        // beside the amount.
        'hours' => ':count hour|:count hours',
        'currency' => 'LYD',
        'starts' => 'Starts :date',
        'date_format' => 'd M Y',

        'no_intake' => 'No intake has been announced yet.',
        'contact_cta' => 'Contact us about the next intake',

        'back' => 'All programmes',
        'ask_heading' => 'Interested in this programme?',
        'ask_body' => 'Write to the centre or call it to ask about this programme.',
        'ask_link' => 'Contact the centre',
    ],

    /*
    | Draft copy pending the owner's text (2026-10-12): replace freely; keep the keys.
    |
    | Contact. Links, not a form: the public pages hold no session, so a form
    | would carry no CSRF token. There is no street address and no opening hours
    | until the owner sends them; the directions link is shown only while one is set.
    */
    'contact' => [
        'empty' => 'Contact details will be published here when available.',
        'heading' => 'Contact',
        'intro' => 'To ask about a programme or an intake, write to the centre or call it.',
        'eyebrow' => 'Visit us',
        'title' => 'Come to the centre',
        'email_label' => 'Email',
        'phone_label' => 'Telephone',
        'directions_title' => 'Directions to the centre',
        'directions_note' => 'Opens the centre\'s location in your own map app.',
        'directions' => 'Get directions',
        'directions_new_tab' => '(opens in a new tab)',
    ],
];
