<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * How one part of a payment was actually handed over.
 *
 * ONE PAYMENT, ONE OR MORE TENDERS
 * --------------------------------
 * This is the column that replaced the original design's single
 * `payments.method`, which cannot represent the split-tender checkout every
 * retail counter performs: 300 on card and 700 in cash is one payment, one
 * receipt, two `payment_tenders` rows with two different values from this enum
 * (design section 5).
 *
 * The payment method breakdown and the daily tender report therefore group by
 * **tender**, never by payment, so a split payment contributes to two methods
 * (design section 8).
 *
 * ONLY `card` CARRIES A DATABASE REQUIREMENT
 * ------------------------------------------
 * `payment_tenders_card_requires_external_reference` is written against the
 * literal 'card' alone: a card tender must carry a non-blank terminal
 * reference, and TRIM in the constraint is what stops a single space passing
 * for one. No other case here is constrained, and none should acquire a rule in
 * PHP that the database does not also hold — an invariant that lives only in
 * application code is one that any other writer reaches around.
 *
 * WHAT IS NEVER STORED
 * --------------------
 * Card numbers, PINs and CVVs, anywhere in this system. `external_reference` is
 * the terminal's own reference, typed by whoever is at the desk, and a
 * validation rule rejects PAN-shaped input — 13 to 19 digits, spaces and dashes
 * ignored.
 *
 * `bank_transfer` AND `other` ARE WIDER THAN DESIGN SECTIONS 5 AND 8 DESCRIBE
 * --------------------------------------------------------------------------
 * Both of those sections speak only of cash and card, as does the
 * `payment_tenders` migration's own comment. The two extra cases were specified
 * for this task and are recorded here rather than silently: the column is
 * `string(30)` with no value constraint, so they store cleanly, and neither is
 * required to carry a terminal reference. **Anything that reports "cash and
 * card" needs to decide what it does with these two** — a report that filters to
 * the two it knows about would drop money from a total without saying so.
 */
enum TenderMethod: string
{
    /** Notes handed across the desk and counted before the payment is recorded. */
    case Cash = 'cash';

    /** Money arriving in the centre's account, evidenced outside this system. */
    case BankTransfer = 'bank_transfer';

    /** An external terminal that has already shown Approved. */
    case Card = 'card';

    /** Anything the four cases above do not describe. */
    case Other = 'other';

    /**
     * The translated label for display.
     *
     * `tender_methodS`, plural, following the rule EmploymentType records: the
     * SINGULAR key is the field label ("Tender method"), and one key cannot be
     * both a string and an array — asking for the singular would hand Filament
     * the case list and fatal on it.
     *
     * `lang/en/payments.php` is task 4's file, so until it exists these render
     * as the raw case value. That is the same state every phase 1 enum shipped
     * in before P1-T14 supplied its catalogue: what matters from commit one is
     * that no user-facing string is written here.
     */
    public function label(): string
    {
        $label = __("payments.tender_methods.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
