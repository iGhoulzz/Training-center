<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public site (phase 4, P4-T11)
|--------------------------------------------------------------------------
|
| Every string on the public layout, its header, navigation and footer, and the
| minimal home page. P4-T12 replaces the home page with the real one, and adds
| its own groups to this file beside these.
|
| Whole sentences, never fragments assembled in code: the copyright line and the
| home heading carry named placeholders so a translator can reorder them. The
| centre's name is config('app.name') and its contact details are in
| config/public_site.php, so neither is a string here.
*/

return [
    'meta' => [
        'description' => 'Courses and certificates from the training centre, with a public register where any certificate can be checked.',
    ],

    'skip_to_content' => 'Skip to the main content',

    'brand' => [
        // A decorative two-letter mark beside the name. It is aria-hidden, so
        // a screen reader hears the centre's name once, not "TC" and then the name.
        'mark' => 'TC',
    ],

    'header' => [
        'contact_label' => 'Contact details',
    ],

    'nav' => [
        'label' => 'Main navigation',
        'menu' => 'Menu',
    ],

    'links' => [
        'verify_certificate' => 'Verify a certificate',
    ],

    'footer' => [
        'description' => 'Training courses, with certificates anyone can check in the public register.',
        'services_heading' => 'Services',
        'copyright' => '© :year :name. All rights reserved.',
    ],

    'home' => [
        'heading' => 'Welcome to :name',
        'lead' => 'Anyone holding the reference printed on a certificate issued by the centre can check it online.',
    ],
];
