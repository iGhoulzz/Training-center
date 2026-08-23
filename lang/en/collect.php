<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enrol and collect — the guided flow's own catalogue (P2-T09)
|--------------------------------------------------------------------------
|
| One catalogue per phase 2 task (design section 12): nine tasks editing a
| single finance.php would be a guaranteed merge conflict, so each keeps its
| own file. This third and final unit adds steps 6 through 8 — optional
| collection, cash/card/split tenders, and finalize.
|
| 'student', 'batch', 'first_name' and the rest are this file's OWN keys
| rather than reused from lang/en/enrollment.php. ChargeResource's own
| header already states the reasoning this follows: the two catalogues are
| owned by different tasks, and reaching across would couple this flow's
| wording to a file it does not own. The same reasoning is why
| 'not_a_card_number' and the tender-method labels stay in lang/en/payments.php
| (P2-T04's own catalogue, already wired to NotACardNumber and
| TenderMethod::label()) rather than being duplicated here.
|
| NOT A SINGLE VALUE HERE MAY SAY "allocation". Design section 2 states it
| outright — the system allocates a payment to a bill, and staff never see
| the term or make that decision. EnrollAndCollectFlowTest scans this file's
| VALUES for it, not its keys or this header.
*/

return [
    'navigation_label' => 'Enrol & Collect',

    'step_student' => 'Student',
    'step_batch' => 'Batch',

    'student' => 'Student',
    'student_code' => 'Student code',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'phone' => 'Phone',

    // Composite strings, each with its own key rather than assembled by
    // joining fragments — design section 12: the separator and the ordering
    // are both localisable.
    'student_option_label' => ':code — :name',

    'batch' => 'Batch',
    'batch_option' => ':code — :course',

    'step_discount' => 'Discount',
    'step_preview' => 'Preview',

    'discount' => 'Discount',
    'no_discount' => 'No discount',
    'discount_option' => ':name (:percentage%)',
    'discount_percentage_value' => ':percentage%',

    'preview_list_price' => 'List price',
    'preview_discount' => 'Discount',
    'preview_final_amount' => 'Amount to bill',
    'preview_pending' => 'Select a batch to see pricing',

    // Composite money string, design section 12's own reasoning: the
    // separator, symbol and ordering are all localisable, so this is a
    // translation key rather than digits concatenated in code.
    'amount_lyd' => ':amount LYD',

    'confirm' => 'Confirm',
    'confirmed' => 'Enrolment confirmed',

    // Steps 6-8: optional collection, tenders, and finalize.
    'collect_amount' => 'Amount to collect',
    'collect_amount_hint' => 'Defaults to the full amount owed. Reduce it to record an installment.',

    'tenders' => 'Tenders',
    'tender_method' => 'Method',
    'tender_amount' => 'Amount',
    'tender_reference' => 'Terminal reference',
    'tender_reference_hint' => 'The reference the card terminal printed on its slip. Never a card number.',
    'add_tender' => 'Add another tender',

    // Shared by the collect-amount and each tender-amount field, the same
    // reasoning ChargeResource::adjustAction() gives for its own
    // amount_format_error: the regex rejects a fourth decimal place, which
    // Money::fromDecimal() would otherwise refuse as a developer diagnostic
    // rather than a readable field error.
    'amount_format_error' => 'Enter an amount with up to three decimal places.',

    // The form-rule mirror of PaymentInvariantService::assertRecordable()'s
    // tender-total check — see EnrollAndCollect::collectForm()'s own
    // docblock for why this is a field rule here rather than a caught
    // exception.
    'tender_total_mismatch' => 'The amounts entered for each tender do not add up to the amount to collect.',

    'collect_action' => 'Collect payment',
    'collected_successfully' => 'Payment recorded.',
    'done' => 'Done',

    // The page's <h1> and browser title. Filament otherwise falls back to a
    // hardcoded English headline of the class name.
    'title' => 'Enrol & Collect',

    // EnrollAndBillAction refused on authorization. Laravel's own
    // AuthorizationException message is hardcoded English, so the panel shows
    // this instead.
    'enrollment_denied' => 'You do not have permission to enrol this student.',

    // A second installment typed into a collection panel that already
    // recorded one: the shared idempotency key makes it a conflict, not a
    // replay. Whole sentence, and it tells the operator what to do next.
    'installment_conflict' => 'This collection has already been recorded. Start the flow again from this student to record another installment.',

    // The shape rule accepts 0.000; this is what refuses it, before
    // TenderData's developer-facing guard can reach a user in English.
    'amount_must_be_positive' => 'Enter an amount greater than zero.',
];
