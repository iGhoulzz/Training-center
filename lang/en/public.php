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
    | Home. The page summarises About, Programmes, the verifier and Contact, and
    | links on to each. It states only what the system makes true: certificates are
    | recorded in a public register that anyone can check, and how to get in touch.
    */
    'home' => [
        'heading' => 'Welcome to :name',
        'lead' => 'Professional and legal training, with certificates that anyone can check in a public register.',
        'programmes_link' => 'Browse programmes',

        'about_heading' => 'About the centre',
        'about_body' => 'The centre runs professional and legal training programmes. Every certificate it issues is entered in a public register, so anyone can confirm it without asking the centre.',
        'about_link' => 'More about the centre',

        'programmes_heading' => 'Our programmes',
        'programmes_all' => 'All programmes',

        'verify_heading' => 'Verify a certificate',
        'verify_body' => 'Anyone holding the reference printed on a certificate issued by the centre can check it online.',

        'contact_heading' => 'Get in touch',
        'contact_body' => 'Questions about a programme or its next intake? Write to the centre or call it.',
        'contact_link' => 'Contact details',
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
        'hours' => ':count hour|:count hours',
        'price' => ':amount LYD',
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
        'heading' => 'Contact',
        'intro' => 'To ask about a programme or an intake, write to the centre or call it.',
        'email_label' => 'Email',
        'phone_label' => 'Telephone',
        'directions' => 'Get directions',
        'directions_new_tab' => '(opens in a new tab)',
    ],
];
