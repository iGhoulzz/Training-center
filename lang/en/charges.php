<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Charges (bills) — corrections (P2-T05)
|--------------------------------------------------------------------------
|
| One catalogue per phase 2 task (design section 12): nine tasks editing a
| single finance.php would be a guaranteed merge conflict, so each keeps its
| own file. This one starts with AdjustChargeAction's and WriteOffChargeAction's
| refusals; ChargeResource — the Filament half of this same task — adds the
| resource's field and action labels here as it is built.
|
| Both keys below are whole sentences, not fragments assembled in code: each
| reaches the user as a Filament notification when its exception is caught,
| and design section 12 requires a composite or user-facing string to be a
| translation key rather than something joined together at the call site. The
| specific figures involved — the attempted amount, what is already allocated,
| which charge — live on the exception's own public properties for whatever
| renders the refusal, not interpolated into the sentence here.
*/

return [
    // AdjustChargeAction: refuses to drop the amount below what has already
    // been allocated from payments that still stand.
    'amount_below_allocated' => 'This charge cannot be adjusted below the amount already allocated to it from payments that still stand.',

    // WriteOffChargeAction: refuses a second write-off of the same charge.
    'already_written_off' => 'This charge has already been written off and cannot be written off again.',

    /*
    |--------------------------------------------------------------------------
    | ChargeResource — labels, filters and the two record actions
    |--------------------------------------------------------------------------
    |
    | Added by the same task (P2-T05); see the file header above for why every
    | phase 2 task keeps its own catalogue. `course` and `batch` are this
    | resource's own keys rather than reused from lang/en/enrollment.php — the
    | two catalogues are owned by different tasks, and reaching across would
    | couple ChargeResource's wording to a file it does not own.
    */

    'charge' => 'Charge',
    'charges' => 'Charges',

    'reference' => 'Bill reference',
    'enrollment_reference' => 'Enrolment reference',
    'course' => 'Course',
    'batch' => 'Batch',
    'list_price' => 'List price',
    'discount' => 'Discount',
    'amount' => 'Billed amount',
    'allocated' => 'Paid so far',
    'outstanding' => 'Outstanding',
    'due_date' => 'Due date',
    'written_off' => 'Written off',
    'written_off_at' => 'Written off on',
    'written_off_by' => 'Written off by',
    'written_off_reason' => 'Write-off reason',

    // Composite strings, each with its own key rather than assembled by
    // joining fragments — design section 12: the separator and the ordering
    // are both localisable.
    'amount_lyd' => ':amount LYD',
    'discount_percentage_value' => ':percentage%',
    'no_discount' => '—',

    'filter_outstanding' => 'Outstanding only',
    'filter_written_off_true' => 'Written off',
    'filter_written_off_false' => 'Not written off',

    // AdjustChargeAction's form.
    'adjust' => 'Adjust',
    'adjust_modal_heading' => 'Adjust this charge',
    'new_amount' => 'New amount',
    'new_amount_hint' => 'For data-entry errors only — never for applying a late discount.',
    'reason' => 'Reason',
    'amount_format_error' => 'Enter an amount with at most three decimal places, for example 125.500.',
    'adjusted_successfully' => 'Charge adjusted.',
    'amount_below_allocated_detail' => 'Attempted :attempted, already allocated :allocated.',

    // WriteOffChargeAction's form.
    'write_off' => 'Write off',
    'write_off_modal_heading' => 'Write off this debt',
    'write_off_reason_hint' => "This does not erase the debt. It stays visible in the student's payment history and is excluded only from the aged outstanding report.",
    'written_off_successfully' => 'Charge written off.',
    'student' => 'Student',
    'bill_details' => 'Bill details',
    'billed' => 'billed',
    'paid_amount' => ':amount paid',
    'payment_state' => 'Payment state',
    'settled' => 'Settled',
    'part_paid' => 'Part paid',
    'unpaid' => 'Unpaid',
    'outstanding_bills' => '{1} Finance · :count bill outstanding|[0,*] Finance · :count bills outstanding',
    'bill_count' => '{1} :count bill|[0,*] :count bills',
    'age_current' => '0–30 days (including not yet due)',
    'age_31_60' => '31–60 days',
    'age_61_90' => '61–90 days',
    'age_91_plus' => '91+ days',
    'view_payments' => 'Outstanding → payments',

    // P4-T32: the "Collect payment" row action, and the student line on a bill.
    'collect_payment' => 'Collect payment',
    'student_label' => ':code — :name',

    // P4-T37: the "How to read this page" panel on the Charges list, and the
    // one-line tooltips on the three row buttons.
    'help_heading' => 'How to read this page',
    'help_cards_title' => 'The four cards at the top',
    'help_cards_total' => 'Each card is the total still owed on unpaid bills, grouped by how long the bill has been owed. The days are counted from the bill\'s due date, and a bill that is not yet due is in the first card.',
    'help_cards_sum' => 'A card adds up every student: two students who each owe 550.000 LYD show 1100.000 LYD together.',
    'help_cards_written_off' => 'Written-off bills are not in the cards.',
    'help_rows_title' => 'Each row',
    'help_row_billed' => 'Billed is the bill total after any discount.',
    'help_row_paid' => 'Paid, with its percentage, is what has been collected so far.',
    'help_row_owed' => 'The coloured amount is what is still owed.',
    'help_row_badge' => 'The badge says :unpaid, :part_paid, :settled or :written_off.',
    'help_buttons_title' => 'The buttons',
    'help_collect_payment' => 'Takes a payment towards what is still owed, the first one or a later installment. Each payment gets its own receipt, and the amount owed goes down by itself.',
    'help_adjust' => 'Changes the bill amount itself, with a reason. Not for recording money received.',
    'help_write_off' => 'Gives up on collecting the debt, with a reason. Not for recording money received. A written-off bill still counts in the student\'s balance.',
    'help_reversal' => 'A reversed payment no longer counts as paid, and its amount is owed again.',
    'tooltip_collect_payment' => 'Take a payment towards what is still owed. Each payment gets its own receipt.',
    'tooltip_adjust' => 'Change the bill amount itself. Not for recording money received.',
    'tooltip_write_off' => 'Give up on collecting this debt. Not for recording money received.',
];
