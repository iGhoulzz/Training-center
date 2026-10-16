<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Expenses and expense categories (P4-T04)
|--------------------------------------------------------------------------
|
| One catalogue per task (design section 12). Money paid out other than wages:
| the managed category list, and the expenses recorded against it.
|
| An expense is never edited and never deleted — a wrong one is reversed and the
| correct figure recorded anew — so there is no "edit expense" or "delete
| expense" string, and none belongs here.
|
| The refusal sentences below reach a user as a form error or a notification, so
| each is a whole sentence rather than a fragment assembled in code. Money is
| formatted through `amount_lyd`, never by joining a figure to a currency symbol
| at the call site: the separator and the ordering are both localisable.
*/

return [
    // Nouns.
    'expense' => 'Expense',
    'expenses' => 'Expenses',
    'category' => 'Category',
    'categories' => 'Expense categories',

    // Expense fields.
    'amount' => 'Amount (LYD)',
    'amount_hint' => 'Up to three decimal places, for example 125.500.',
    'amount_lyd' => ':amount LYD',
    'paid_on' => 'Date paid',
    'paid_on_hint' => 'The date the money left. It decides which month this expense counts in, and it cannot be after today.',
    'description' => 'Description',
    'recorded_by' => 'Recorded by',

    // Expense actions and states.
    'record_expense' => 'Record an expense',
    'view_expense' => 'View expense',
    'reversed' => 'Reversed',
    'reversed_on' => 'Reversed on',
    'reversed_by' => 'Reversed by',
    'filter_reversed_true' => 'Reversed expenses only',
    'filter_reversed_false' => 'Standing expenses only',

    // Reversal. A reversal is how a wrong expense is corrected; it is not a refund.
    'reverse' => 'Reverse',
    'reverse_modal_heading' => 'Reverse this expense',
    'reverse_modal_description' => 'A reversed expense stops counting towards profit, including in the month it was paid. The record stays. Record the correct expense separately.',
    'reason' => 'Reason',
    'reverse_reason_hint' => 'Why this expense should not have been recorded. This is kept with your name in the activity log.',
    'reversed_successfully' => 'The expense was reversed.',
    'already_reversed' => 'This expense has already been reversed.',

    // Category fields and actions.
    'category_name_en' => 'Name (English)',
    'category_name_ar' => 'Name (Arabic)',
    'category_is_active' => 'Active',
    'category_is_active_hint' => 'Switch off to stop offering this category for new expenses. Expenses already recorded keep it.',
    'category_active_only' => 'Active categories only',
    'category_inactive_only' => 'Inactive categories only',
    'all_categories' => 'All categories',
    'not_translated' => 'Not translated',
    'create_category' => 'New category',
    'edit_category' => 'Edit category',

    // Refusals.
    'category_unavailable' => 'Choose an active expense category.',
    'amount_invalid' => 'Enter an amount in dinars with at most three decimal places, for example 125.500.',
    'amount_must_be_positive' => 'The amount must be more than zero.',
    'refused_unauthorized' => 'You are not allowed to do that.',
    'refused_invalid' => 'That could not be saved. Check the details and try again.',
    'category_details' => 'Category details',
    'category_lifecycle_hint' => 'Categories are deactivated when no longer needed. Their expense history stays available.',
    'payment_details' => 'Expense details',
    'immutable_hint' => 'Record money already paid. A correction reverses this expense and records a new one.',
];
