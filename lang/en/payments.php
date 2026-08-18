<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments — recording money against a bill (P2-T04)
|--------------------------------------------------------------------------
|
| One catalogue per phase 2 task (design section 12): nine tasks editing a
| single finance.php would be a guaranteed merge conflict, so each keeps its
| own file. This one starts with PaymentInvariantService's two refusals and
| the tender-method catalogue TenderMethod::label() already reads; the
| Filament half of this task adds the resource's field and action labels
| here as it is built.
|
| Both refusal keys below are whole sentences, not fragments assembled in
| code: each reaches the user as a Filament notification when its exception
| is caught, and design section 12 requires a composite or user-facing
| string to be a translation key rather than something joined together at
| the call site. The specific figures — the amount tendered, the amount
| allocated, what remains outstanding — live on the exception's own public
| properties for whatever renders the refusal, not interpolated into the
| sentence here.
*/

return [
    // PaymentInvariantService::assertRecordable(): the tenders recorded do
    // not sum to the amount allocated to the bill.
    'tender_allocation_mismatch' => 'The amounts tendered do not add up to the amount allocated to this bill.',

    // PaymentInvariantService::assertRecordable(): the allocation would take
    // the bill below zero outstanding.
    'exceeds_outstanding' => 'This payment is more than the bill still owes.',

    // RecordPaymentAction::replayOrConflict(): the idempotency key was
    // already used to record a different payment.
    'idempotency_conflict' => 'This payment key has already been used to record a different payment.',

    // ReversePaymentAction::execute(): a second reversal was attempted
    // against a payment that already carries one.
    'already_reversed' => 'This payment has already been reversed.',

    // NotACardNumber::validate(): the value strips to 13-19 digits, which is
    // PAN-shaped rather than an ordinary terminal reference. This unit ships
    // the rule with no caller yet — see that class's own docblock for why —
    // so this key reaches a user for the first time once task 9 wires the
    // collection form up to it.
    'not_a_card_number' => 'This does not look like a terminal reference — it looks like a card number, which this system never stores. Re-check what was typed.',

    /*
    |--------------------------------------------------------------------------
    | TenderMethod::label()
    |--------------------------------------------------------------------------
    |
    | `tender_methodS`, plural — see that enum's own docblock for why: the
    | singular key is reserved for a field label ("Tender method"), and one
    | key cannot be both a string and this array.
    */
    'tender_methods' => [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'other' => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | PaymentResource — labels, filters and the one record action
    |--------------------------------------------------------------------------
    |
    | Added by the same task (P2-T04, unit 5); see the file header above for
    | why every phase 2 task keeps its own catalogue. `student` is this
    | resource's own key rather than reused from another catalogue — the
    | same reasoning ChargeResource's own `course` and `batch` keys give for
    | not reaching into a file this task does not own.
    */

    'payment' => 'Payment',
    'payments' => 'Payments',

    'reference' => 'Receipt reference',
    'student' => 'Student',
    'received_at' => 'Received',
    'total' => 'Amount received',
    'bill' => 'Bill',
    'recorded_by' => 'Recorded by',
    'reversed' => 'Reversed',
    'reversed_on' => 'Reversed on',
    'reversed_by' => 'Reversed by',

    // A composite string with its own key rather than assembled by joining
    // fragments — design section 12: the separator and the ordering are
    // both localisable. Deliberately a second key rather than reusing
    // charges.amount_lyd: the two catalogues are owned by different tasks,
    // and reaching across would couple this resource's wording to a file it
    // does not own.
    'amount_lyd' => ':amount LYD',

    'filter_reversed_true' => 'Reversed',
    'filter_reversed_false' => 'Not reversed',

    // ReversePaymentAction's form.
    'reverse' => 'Reverse',
    'reverse_modal_heading' => 'Reverse this payment',
    'reason' => 'Reason',
    // Whole sentences, and load-bearing rather than decorative: design
    // section 1 rules out refunds entirely, so an operator reaching for this
    // button to document cash handed back would be recording something the
    // system cannot represent. The hint says so where the decision is made.
    'reverse_reason_hint' => 'Use this only for a payment that should never have been recorded — entered against the wrong bill, duplicated, or never actually received. It does not record money being returned to the student, and the centre does not issue refunds.',
    'reversed_successfully' => 'Payment reversed.',
];
