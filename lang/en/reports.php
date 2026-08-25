<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Financial reports',
    'empty' => 'No rows match these filters.',
    'pages' => [
        'revenue' => ['navigation' => 'Revenue', 'title' => 'Revenue by course and batch'],
        'outstanding_aged' => ['navigation' => 'Outstanding balances', 'title' => 'Outstanding balances, aged'],
        'payment_method' => ['navigation' => 'Payment methods', 'title' => 'Payment method breakdown'],
        'daily_tender' => ['navigation' => 'Daily tender', 'title' => 'Daily tender report'],
        'wage_cost' => ['navigation' => 'Wage cost', 'title' => 'Wage cost'],
        'profit' => ['navigation' => 'Profit', 'title' => 'Profit'],
        'student_payment_history' => ['navigation' => 'Student payments', 'title' => 'Student payment history'],
    ],
    'filters' => [
        'from' => 'From',
        'to' => 'To',
        'date' => 'Date',
        'month' => 'Month',
        'student_id' => 'Student',
        'choose_student' => 'Choose a student',
        'not_selected' => 'Not selected',
    ],
    'actions' => [
        'apply_filters' => 'Apply filters',
        'export_xlsx' => 'Export Excel',
        'export_pdf' => 'Export PDF',
        'download_pdf' => 'Download PDF',
        'download_xlsx' => 'Download Excel',
    ],
    'notifications' => [
        'xlsx_ready' => 'Your Excel export is ready. :count row was exported.|Your Excel export is ready. :count rows were exported.',
        'pdf_queued' => 'Your PDF export was queued.',
        'pdf_ready' => 'Your PDF export is ready.',
    ],
    'values' => ['yes' => 'Yes', 'no' => 'No'],
    'columns' => [
        'revenue' => [
            'course_code' => 'Course', 'course_total' => 'Course revenue (LYD)',
            'batch_code' => 'Batch', 'batch_total' => 'Batch revenue (LYD)',
        ],
        'outstanding' => [
            'charge_reference' => 'Bill', 'student_code' => 'Student code',
            'student_name' => 'Student', 'due_date' => 'Due date',
            'days_past_due' => 'Days past due', 'bucket' => 'Age bucket',
            'outstanding' => 'Outstanding (LYD)',
        ],
        'outstanding_aged' => [
            'charge_reference' => 'Bill', 'student_code' => 'Student code',
            'student_name' => 'Student', 'due_date' => 'Due date',
            'days_past_due' => 'Days past due', 'bucket' => 'Age bucket',
            'outstanding' => 'Outstanding (LYD)',
        ],
        'payment_method' => ['method' => 'Payment method', 'total' => 'Total (LYD)'],
        'daily_tender' => ['method' => 'Payment method', 'total' => 'Total (LYD)'],
        'wage_cost' => ['staff_name' => 'Staff member', 'total' => 'Wage cost (LYD)'],
        'profit' => [
            'revenue' => 'Collected revenue (LYD)', 'wage_cost' => 'Wage cost (LYD)',
            'profit' => 'Profit (LYD)',
        ],
        'student_payment_history' => [
            'student_code' => 'Student code', 'student_name' => 'Student',
            'charge_reference' => 'Bill', 'charge_amount' => 'Bill amount (LYD)',
            'due_date' => 'Due date', 'written_off' => 'Written off',
            'receipt_references' => 'Receipts', 'receipt_amounts' => 'Receipt amounts (LYD)',
            'collected_total' => 'Student collected total (LYD)',
        ],
    ],
];
