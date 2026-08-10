<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll run: draft, then finalized, then immutable.
 *
 * FINALIZED MEANS POSTED, NOT PAID
 * --------------------------------
 * Finalizing approves a run and posts it to the books. **It does not disburse
 * money.** Payroll disbursement is out of scope for the entire project, there is
 * no paid/unpaid flag here, and wage-cost reports count finalized lines as cost
 * incurred (design section 7). Anyone reading "finalized" as "the staff have
 * their money" is reading it wrong, and this table is where that would start.
 *
 * THREE RUN TYPES, AND EACH HAS A DIFFERENT RELATIONSHIP TO TIME
 * -------------------------------------------------------------
 * `monthly_salary` covers a period, and its lines are month-and-rate segments
 * inside that period. `instructor_batch` is on demand — the draft lists every
 * instructor-hour assignment not already paid in a finalized run, whatever the
 * batch's status, and a batch is paid as one lump; a period would be
 * meaningless, because `batch_instructor.assigned_hours` is per batch while a
 * period is per calendar. `adjustment` corrects a line in a run that is already
 * finalized, and posts to the corrected line's period, not its own.
 *
 * That is why `period_start` and `period_end` are nullable and why a `CHECK`
 * decides which type may carry them: a nullable column with no constraint would
 * let an instructor run acquire a period that nothing would ever honour.
 *
 * NO STATUS COLUMN, HERE EITHER
 * -----------------------------
 * Draft versus finalized is derived from `finalized_at`, the same rule design
 * section 4 sets for charges and an architecture test enforces across the whole
 * Finance domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();

            /*
             * 'monthly_salary', 'instructor_batch' or 'adjustment', cast to a
             * backed enum on the model. Indexed per design section 9 — the runs
             * list is filtered by type, and finalization dispatches on it.
             *
             * No default: there is no run type that is more usual than the
             * others, and defaulting would let a run that forgot to say what it
             * was look like a salary run.
             */
            $table->string('type', 30)->index();

            /*
             * Only a `monthly_salary` run has these, and it always has both —
             * see the CHECK below. Dates, because a period is a local calendar
             * range that the salary segmentation intersects with calendar
             * months.
             *
             * A run may legitimately cover a partial previous month plus a full
             * current one: the centre pays when it pays, and a period is not
             * always a calendar month (design section 7).
             */
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();

            /*
             * Set once, at finalization, with its actor. Indexed because the
             * runs list separates drafts from posted runs on it, and because a
             * draft run is the only kind that is ever deletable — a question
             * asked of this column every time.
             */
            $table->timestamp('finalized_at')->nullable()->index();
            $table->foreignId('finalized_by')->nullable()->index()->constrained('users')->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
        });

        /*
         * PERIOD DATES BELONG TO ONE RUN TYPE.
         *
         * A monthly_salary run has both; instructor_batch and adjustment runs
         * have neither. Enumerating the two rather than writing
         * `type <> 'monthly_salary'` also pins `type` to the three known values,
         * so a typo'd or invented type satisfies neither branch and is refused.
         * That is the intended side effect: a fourth run type would need a
         * period rule decided for it, and this constraint is where that decision
         * has to be made rather than defaulted into.
         *
         * The literals are deliberately not PayrollRunType cases. A migration
         * that has run is never edited, so it must not depend on application
         * code that may later be renamed — the same rule the batches and
         * staff_profiles migrations record for their status defaults.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs
            ADD CONSTRAINT payroll_runs_period_matches_type
            CHECK (
                (type = 'monthly_salary' AND period_start IS NOT NULL AND period_end IS NOT NULL)
                OR (type IN ('instructor_batch', 'adjustment') AND period_start IS NULL AND period_end IS NULL)
            )
        SQL);

        /*
         * The same ordering constraint `staff_compensation` already carries, for
         * the same reason: a period that ends before it begins produces segments
         * of negative length, and every figure derived from them is wrong while
         * looking arithmetically fine.
         *
         * `period_end IS NULL` covers the two typeless-period run types, whose
         * `period_start` is null too — a comparison against NULL yields NULL
         * rather than false, so the explicit first disjunct is what keeps them
         * satisfying this.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs
            ADD CONSTRAINT payroll_runs_period_end_not_before_start
            CHECK (period_end IS NULL OR period_end >= period_start)
        SQL);

        /*
         * An approval with no approver, or an approver with no time, is not a
         * state this table should be able to hold. Design section 14 requires
         * this one to be proven at the database — by inserting a run with a
         * finalized time and no actor and watching MySQL refuse — rather than by
         * asserting that a button is hidden.
         *
         * Both operands are known booleans, never NULL, so the comparison is
         * total.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs
            ADD CONSTRAINT payroll_runs_finalization_columns_paired
            CHECK ((finalized_at IS NULL) = (finalized_by IS NULL))
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints with it.
        Schema::dropIfExists('payroll_runs');
    }
};
