<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How one payment was actually handed over: 300 on card and 700 in cash is one
 * payment, one receipt, two rows here.
 *
 * This table replaces the original design's single `payments.method` column,
 * which cannot represent the split-tender checkout every retail counter performs
 * (design section 5). It is also why `payments` has no amount: the total is the
 * sum of these rows, never a stored copy of it.
 *
 * THE FOREIGN KEY RESTRICTS, AND THAT IS NOT AN OVERSIGHT
 * ------------------------------------------------------
 * A tender is a genuine child record, and this project's rule (`docs/ENGINEERING.md`)
 * would normally make that `cascadeOnDelete()`. Design section 9 chooses
 * restrict anyway, deliberately: **restricting makes the parent payment
 * undeletable at the database level while any tender exists.**
 *
 * Design section 5 says a payment row has no delete path at all —
 * `PaymentPolicy::delete()` returns false unconditionally and `delete_payment`
 * is not even seeded. Cascading here would leave that claim resting entirely on
 * application code: anything that reached a `DELETE` would take the tenders with
 * it and leave no trace that money had been recorded. Restricting expresses the
 * immutability where it cannot be argued with. A payment that must be undone is
 * reversed, never deleted.
 *
 * CARD NUMBERS ARE NEVER STORED
 * -----------------------------
 * `external_reference` is the terminal's own reference, typed by whoever is at
 * the desk. A validation rule rejects PAN-shaped input — 13 to 19 digits, spaces
 * and dashes ignored — and no PIN or CVV is stored anywhere in this system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_tenders', function (Blueprint $table): void {
            $table->id();

            // Restricts. See the class docblock — this one is the point, not a
            // copy-paste of the financial default.
            $table->foreignId('payment_id')->index()->constrained()->restrictOnDelete();

            /*
             * 'cash' or 'card', cast to a backed enum on the model.
             * string(30) with an index, per `docs/ENGINEERING.md`, but with NO
             * default: there is no method a tender defaults to, and a default
             * would let a row that forgot to say how the money arrived look like
             * a deliberate cash payment.
             *
             * Indexed because the payment method breakdown groups on it, and
             * the daily tender report totals cash and card separately (design
             * section 8) — by tender, never by payment, so a split payment
             * contributes to both.
             */
            $table->string('method', 30)->index();

            // decimal(12, 3). Never float, never two places.
            $table->decimal('amount', 12, 3);

            // The card terminal's reference. Free text, nullable for cash, and
            // required-and-non-blank for card by the CHECK below.
            $table->string('external_reference', 100)->nullable();

            $table->timestamps();
        });

        /*
         * A zero tender records nothing and a negative one is a refund, which
         * design section 1 rules out of the whole system. Strictly greater than
         * zero, unlike `charges.amount` — a bill can legitimately be 0.000 after
         * a full waiver, but nobody hands over nothing.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payment_tenders
            ADD CONSTRAINT payment_tenders_amount_positive
            CHECK (amount > 0)
        SQL);

        /*
         * A CARD TENDER CARRIES A NON-BLANK TERMINAL REFERENCE.
         *
         * The blankness test is the whole point of this constraint and not a
         * flourish. A nullability check alone accepts a single space, and a
         * space is not a reference — it is an operator tabbing past a field,
         * and it leaves a card transaction with nothing to reconcile against
         * the terminal's own log. Design section 5 states the requirement as
         * "non-blank"; design section 14 requires it to be proven by inserting
         * a whitespace reference and watching MySQL refuse.
         *
         * WHY REGEXP AND NOT TRIM
         * -----------------------
         * This was written as TRIM(external_reference) <> '' first, and the
         * schema test that proves it caught the gap: MySQL's single-argument
         * TRIM strips SPACES ONLY. A reference of one tab or one newline
         * satisfies TRIM(x) <> '' and was accepted — blank by every meaning
         * that matters, and accepted by a constraint whose stated job is to
         * refuse blanks. Measured, not assumed.
         *
         * REGEXP '[^[:space:]]' asks the question the design actually asks:
         * does this value contain at least one non-whitespace character. It is
         * deterministic, so MySQL permits it in a CHECK.
         *
         * The literal 'card' is deliberately not TenderMethod::Card->value. A
         * migration that has run is never edited, so it must not depend on
         * application code that may later be renamed — the same reasoning the
         * batches and staff_profiles migrations record for their status
         * defaults.
         *
         * Cash tenders are unaffected: the first disjunct is true for them, so
         * the reference stays optional.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payment_tenders
            ADD CONSTRAINT payment_tenders_card_requires_external_reference
            CHECK (
                method <> 'card'
                OR (external_reference IS NOT NULL AND external_reference REGEXP '[^[:space:]]')
            )
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints with it.
        Schema::dropIfExists('payment_tenders');
    }
};
