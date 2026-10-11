<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The finance hub (phase 4, P4-T07)
|--------------------------------------------------------------------------
|
| A landing page that tiles the finance screens and reports which already
| exist. Every tile is named by its id in FinanceHub::TILES, and its one-line
| description lives under tiles.<id>.description here.
|
| A tile's TITLE is not here, on purpose: it is the destination's own
| navigation label (FinanceHub reads getNavigationLabel() from the destination
| class), so a tile reads exactly as the screen it leads to and cannot drift
| from it. The same reasoning keeps every permission name out of the hub.
|
| The tile keys are built from an id, which LocalizationTest's key scan cannot
| see. FinanceHubTest checks every key below resolves.
*/

return [
    'title' => 'Finance',
    'navigation_label' => 'Finance',

    'groups' => [
        'money_in' => [
            'heading' => 'Money in',
            'description' => 'What students are billed and what they pay.',
        ],
        'money_out' => [
            'heading' => 'Money out',
            'description' => 'What the centre pays to its staff and for its running costs.',
        ],
        'reports' => [
            'heading' => 'Reports',
            'description' => 'Figures worked out from the records above.',
        ],
    ],

    'tiles' => [
        'charges' => [
            'description' => 'The bills raised for enrolments, and how much of each is still owed.',
        ],
        'payments' => [
            'description' => 'Money received from students, with its receipts.',
        ],
        'discounts' => [
            'description' => 'The discounts that can be applied to a bill.',
        ],
        'enroll_and_collect' => [
            'description' => 'Enrol a student in a batch, bill them and take payment in one go.',
        ],
        'payroll_runs' => [
            'description' => 'Work out and finalise each month\'s staff pay.',
        ],
        'staff_compensation' => [
            'description' => 'The pay rate agreed with each staff member, and the date it starts.',
        ],
        'expenses' => [
            'description' => 'Money the centre has paid out, such as rent and supplies.',
        ],
        'expense_categories' => [
            'description' => 'The categories an expense can be recorded under.',
        ],
        'revenue_report' => [
            'description' => 'Money collected, by course and batch.',
        ],
        'outstanding_aged_report' => [
            'description' => 'What students still owe, grouped by how long it has been due.',
        ],
        'payment_method_report' => [
            'description' => 'How much was collected by each payment method.',
        ],
        'daily_tender_report' => [
            'description' => 'A day\'s takings by payment method, for closing the till.',
        ],
        'wage_cost_report' => [
            'description' => 'Finalised staff pay over a period.',
        ],
        'profit_report' => [
            'description' => 'Money collected, less staff pay and expenses.',
        ],
        'student_payment_history_report' => [
            'description' => 'Every payment one student has made.',
        ],
    ],
];
