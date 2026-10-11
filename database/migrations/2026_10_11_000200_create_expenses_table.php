<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid out other than wages (phase 4, spec section 6).
 *
 * AN EXPENSE IS NEVER EDITED AND NEVER DELETED
 * --------------------------------------------
 * `amount`, `paid_on`, `expense_category_id` and `description` have no write
 * path after the row exists. A wrong expense is reversed once and the correct
 * figure is recorded as a new row, exactly as a wrong payment is. Profit counts
 * only non-reversed expenses, so a reversal moves the profit of the month the
 * expense was paid in, and it does so through a recorded, attributed act.
 *
 * `paid_on` IS A CENTRE-LOCAL CALENDAR DATE, NOT AN INSTANT
 * ---------------------------------------------------------
 * It is the date the money left, and it alone decides which month an expense
 * counts in. It is a `date`, so it carries no zone and is never converted:
 * reports compare it with bare local `Y-m-d` boundaries, the way
 * `payroll_lines.posting_period_start` is compared. Indexed because every
 * profit figure filters on a range of it.
 *
 * NO TOTAL IS STORED
 * ------------------
 * `amount` is the only money column. Any total is a SUM() over these rows.
 *
 * Every foreign key restricts: a category or an account that has an expense on
 * record is not hard-deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 3);

            $table->date('paid_on')->index();

            $table->string('description', 500);

            // The staff member who entered it. Restricts, like every other
            // financial foreign key to users.
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();

            /*
             * REVERSAL — a set-once lifecycle transition on an otherwise
             * immutable row. Super admin only, via `reverse_expense`. Not
             * indexed: almost every row is null, so a B-tree on it has nothing
             * selective to seek, and the filter is always applied beside a
             * `paid_on` range.
             */
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();
        });

        /*
         * All three reversal columns or none. A reversal with no actor or no
         * reason is not a state this table should be able to hold, and the
         * refusal belongs in the database rather than only in
         * `ReverseExpenseAction`: an Action's check protects the paths that go
         * through it, a constraint protects the rest. Same shape as
         * `payments_reversal_columns_paired`.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_reversal_columns_paired
            CHECK (
                (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL)
            )
        SQL);

        // Money paid out is a positive amount. A correction is a reversal, never
        // a negative row, so nothing here needs to be signed.
        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_amount_positive
            CHECK (amount > 0)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints with it.
        Schema::dropIfExists('expenses');
    }
};
