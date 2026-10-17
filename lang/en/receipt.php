<?php

declare(strict_types=1);

return [
    'title' => 'Payment receipt',
    'reference' => 'Receipt reference',
    'student' => 'Student',
    'student_identity' => ':code — :name',
    'enrollment' => 'Enrolment reference',
    'course' => 'Course code',
    'batch' => 'Batch code',
    'bill' => 'Bill reference',
    'original_price' => 'Original price',
    'discount' => 'Discount',
    'final_charge' => 'Final charge',
    'amount_paid' => 'Amount paid',
    'tender' => 'Tender',
    'tender_label' => 'Tender: :method',
    'remaining_balance' => 'Remaining balance',
    'payment_date' => 'Payment date',
    'recorded_by' => 'Recorded by',
    'amount_lyd' => ':amount LYD',

    // The discount is a percentage, shown beside three-decimal LYD amounts on
    // both the A4 PDF and the 80 mm page (P4-T24).
    'discount_percent' => ':percent%',

    // The 80 mm print page (P4-T24).
    'print_page_title' => 'Receipt :reference',
    'print_again' => 'Print again',
];
