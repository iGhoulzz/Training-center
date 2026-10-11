<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The finance hub (phase 4, P4-T07)
|--------------------------------------------------------------------------
|
| A landing page that tiles the finance screens and reports which already
| exist. Every tile is named by its id in FinanceHub::TILES, and its wording
| lives under tiles.<id>.label and tiles.<id>.description here.
|
| A tile's label is deliberately its own string rather than a borrowed one:
| the destination's catalogue belongs to the task that built the destination,
| and reaching across would tie this page's wording to a file it does not own.
|
| The catalogue's name has a hyphen in it, which LocalizationTest's key scan
| does not match, and the tile keys are built from an id, which it cannot see
| either. FinanceHubTest checks every key below resolves.
*/

return [
    'title' => 'Finance',
    'navigation_label' => 'Overview',
    'navigation_group' => 'Finance',

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
            'label' => 'Charges',
            'description' => 'The bills raised for enrolments, and how much of each is still owed.',
        ],
        'payments' => [
            'label' => 'Payments',
            'description' => 'Money received from students, with its receipts.',
        ],
        'discounts' => [
            'label' => 'Discounts',
            'description' => 'The discounts that can be applied to a bill.',
        ],
        'enroll_and_collect' => [
            'label' => 'Enrol & collect',
            'description' => 'Enrol a student in a batch, bill them and take payment in one go.',
        ],
        'payroll_runs' => [
            'label' => 'Payroll runs',
            'description' => 'Work out and finalise each month\'s staff pay.',
        ],
        'staff_compensation' => [
            'label' => 'Staff compensation',
            'description' => 'The pay rate agreed with each staff member, and the date it starts.',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'description' => 'Money the centre has paid out, such as rent and supplies.',
        ],
        'expense_categories' => [
            'label' => 'Expense categories',
            'description' => 'The categories an expense can be recorded under.',
        ],
        'revenue_report' => [
            'label' => 'Revenue',
            'description' => 'Money collected, by course and batch.',
        ],
        'outstanding_aged_report' => [
            'label' => 'Outstanding balances',
            'description' => 'What students still owe, grouped by how long it has been due.',
        ],
        'payment_method_report' => [
            'label' => 'Payment methods',
            'description' => 'How much was collected by each payment method.',
        ],
        'daily_tender_report' => [
            'label' => 'Daily tender',
            'description' => 'A day\'s takings by payment method, for closing the till.',
        ],
        'wage_cost_report' => [
            'label' => 'Wage cost',
            'description' => 'Finalised staff pay over a period.',
        ],
        'profit_report' => [
            'label' => 'Profit',
            'description' => 'Money collected, less staff pay and expenses.',
        ],
        'student_payment_history_report' => [
            'label' => 'Student payments',
            'description' => 'Every payment one student has made.',
        ],
    ],
];
