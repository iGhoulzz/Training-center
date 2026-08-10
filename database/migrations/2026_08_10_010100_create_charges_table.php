<?php

declare(strict_types=1);

use App\Domain\Finance\Support\Reference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bill. One per enrolment, raised as part of enrolling, payable in parts.
 *
 * THERE IS NO STATUS COLUMN, AND THAT IS THE DESIGN
 * -------------------------------------------------
 * The system design's original `charges.status` enum was
 * `unpaid | partial | paid | waived`. Three of those four are derived from
 * allocations, which the same document forbids storing — they are the
 * `paid_amount` mistake wearing a different name (design section 4).
 *
 * So this table stores the **facts a human decided** and nothing computed:
 * what was billed, and whether someone wrote it off. Unpaid / partial / paid are
 * summed from `payment_allocations` at read time by `ChargeBalance`, which is
 * the single definition of outstanding for both SQL and PHP.
 *
 * **`ChargeBalance` reads `charges.amount` as raw SQL**, alongside
 * `payment_allocations.amount`, `.charge_id`, `.payment_id` and
 * `payments.reversed_at`. Those five names are load-bearing across a file that
 * Eloquent cannot rename for you: a column rename here compiles fine and fails
 * at runtime, on a balance. Rename nothing without opening that class.
 *
 * EVERY FIGURE IS FROZEN AT ISSUE
 * -------------------------------
 * `list_price`, `discount_percentage` and `amount` record what was billed on the
 * day it was billed. A later price change, or a discount definition being
 * deactivated, moves nothing here (design section 3). After an `AdjustChargeAction`
 * correction `amount` no longer equals `list_price × (100 − percentage) ÷ 100`,
 * and that is expected: the frozen figures record what was billed, the activity
 * log records that a human corrected it. The table carries no adjustment
 * columns because the append-only log *is* that audit record.
 *
 * WHY THE FOREIGN KEYS RESTRICT
 * -----------------------------
 * Financial rows are never destroyed as a side effect of tidying something else.
 * A bill is the centre's record that money was owed; an enrolment carrying one
 * is deleted only through `DeleteEnrollmentAction`, which calls
 * `DeleteUncommittedChargeAction` to remove the bill first and refuses if it
 * carries any allocation, adjustment or write-off (design section 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();

            /*
             * ONE BILL PER ENROLMENT, enforced where it cannot be raced.
             * unique() gives both that guarantee and the foreign key's index,
             * exactly as `staff_profiles.user_id` already does — that table
             * carries one `..._user_id_unique` index and no separate implicit
             * one, which is the shape this column copies.
             *
             * Design section 1 forbids standalone fees: every charge belongs to
             * an enrolment, and the enrolment can only ever have this one.
             */
            $table->foreignId('enrollment_id')->unique()->constrained()->restrictOnDelete();

            /*
             * `CHG-2026-000042`. NOT NULL UNIQUE from the first byte, which is
             * only possible because the row is inserted carrying a UUID
             * placeholder and updated to its real value inside the same
             * transaction (design section 2).
             *
             * The width comes from Reference::COLUMN_LENGTH rather than a
             * literal, because the longest value this column ever holds is a
             * placeholder — the marker plus a 36-character UUID — not the
             * 15-character reference. The two agreeing by coincidence is a
             * latent bug; agreeing by construction is not.
             */
            $table->string('reference', Reference::COLUMN_LENGTH)->unique();

            /*
             * What the batch cost on the day, after inheritance from the course
             * was resolved (design section 3). Frozen: a null `batches.price`
             * falls back to `courses.default_price` on every read, but an issued
             * charge does not move when either changes.
             *
             * decimal(12, 3), never float and never two places. LYD subdivides
             * into 1000 dirham per ISO 4217.
             */
            $table->decimal('list_price', 12, 3);

            /*
             * The discount applied, if any. Restricts on delete so a definition
             * that has been used cannot be removed out from under the bills that
             * explain themselves by it — see the discounts migration.
             *
             * index() before constrained() so the "index every foreign key" rule
             * is visible in the source rather than left to MySQL's implicit one.
             */
            $table->foreignId('discount_id')->nullable()->index()->constrained()->restrictOnDelete();

            // The frozen percentage. Kept alongside discount_id rather than read
            // through it, so a receipt reprinted years later still shows the
            // figure that was actually applied.
            $table->decimal('discount_percentage', 5, 2)->nullable();

            // What is owed: round(list_price × (100 − percentage) ÷ 100) in
            // integer dirham at issue, or whatever AdjustChargeAction corrected
            // it to afterwards.
            $table->decimal('amount', 12, 3);

            /*
             * THE DATE THE CHARGE WAS RAISED — the enrolment date (design
             * section 4). The centre bills at enrolment and expects payment at
             * or near it; there is no invoicing term to express, so aging
             * measures from the day the student incurred the debt.
             *
             * A `date`, not a timestamp: aging is counted in whole days against
             * the local calendar in Africa/Tripoli, and a date column holds that
             * without a timezone conversion on every read.
             *
             * Indexed because the aged outstanding report both filters and
             * buckets on it (design section 8).
             */
            $table->date('due_date')->index();

            /*
             * A debt the centre has accepted it will never collect.
             * `WriteOffChargeAction`, super admin only, mandatory reason.
             *
             * Writing off does NOT pay the bill: `ChargeBalance` deliberately
             * does not subtract it, so the balance on a receipt and the balance
             * in the payment history agree. The aged report filters on
             * `written_off_at` instead (design section 4).
             *
             * `written_off_at` is NOT indexed, on purpose. Almost every row is
             * null, so a B-tree on it has nothing selective to seek and the
             * aged report's real access path is `due_date`. This is the same
             * reasoning that leaves `courses.name_ar` unindexed: an index that
             * is never chosen still costs every write.
             */
            $table->timestamp('written_off_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('written_off_reason')->nullable();

            $table->timestamps();
        });

        /*
         * A bill for a negative amount is a refund, and design section 1 rules
         * refunds out of the whole system. Zero is allowed: a 100% discount
         * produces a 0.000 charge rather than no charge, so the enrolment still
         * has the bill every later report joins through.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE charges
            ADD CONSTRAINT charges_amount_not_negative
            CHECK (amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE charges
            ADD CONSTRAINT charges_list_price_not_negative
            CHECK (list_price >= 0)
        SQL);

        /*
         * Both discount columns move together or neither does. A `discount_id`
         * with no percentage cannot explain the amount it produced, and a
         * percentage with no id cannot say which pre-approved discount was
         * chosen — and design section 3 requires that choosing a discount is a
         * `apply_discount` decision, traceable to a definition.
         *
         * `(a IS NULL) = (b IS NULL)` rather than a two-branch OR: both
         * operands are known booleans, never NULL, so the comparison is total.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE charges
            ADD CONSTRAINT charges_discount_columns_paired
            CHECK ((discount_id IS NULL) = (discount_percentage IS NULL))
        SQL);

        /*
         * All three write-off columns or none. A write-off with no actor or no
         * reason is exactly the state the mandatory-reason rule exists to
         * prevent, and it is not one this table should be able to hold — the
         * refusal belongs here rather than only in the Action, because an
         * Action's check can be bypassed by anything that writes around it.
         *
         * Spelled as two explicit branches rather than chained equalities,
         * because three columns do not compose into a single `=` and the
         * two-branch form is what a reviewer can read at a glance.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE charges
            ADD CONSTRAINT charges_write_off_columns_paired
            CHECK (
                (written_off_at IS NULL AND written_off_by IS NULL AND written_off_reason IS NULL)
                OR (written_off_at IS NOT NULL AND written_off_by IS NOT NULL AND written_off_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints with it.
        Schema::dropIfExists('charges');
    }
};
