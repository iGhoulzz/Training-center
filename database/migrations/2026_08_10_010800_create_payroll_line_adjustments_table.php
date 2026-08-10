<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bonus or a deduction applied to a payroll line **while its run is still a
 * draft**.
 *
 * THIS IS NOT THE CORRECTION MECHANISM, AND THE TWO ARE EASY TO CONFUSE
 * --------------------------------------------------------------------
 * There are two ways an amount moves, and they differ in *when*, not in kind
 * (design section 7):
 *
 *   - **Before the run is posted** — a row here. Something known in advance: a
 *     bonus the manager decided, a deduction for unpaid leave.
 *   - **After the run is finalized** — an `adjustment` run, whose lines carry
 *     `corrects_payroll_line_id` and post to the corrected line's period. A
 *     finalized run is immutable: no edits, no deletions, and **no new rows
 *     here**. Design revision 1 offered only this table, which does not solve a
 *     mistake discovered next month.
 *
 * The "adjustments only while draft" invariant is a lock and a check inside the
 * writing transaction (design section 11), not a constraint here — a MySQL
 * `CHECK` cannot read `payroll_runs.finalized_at` through two tables.
 *
 * CASCADE IS CORRECT
 * ------------------
 * An adjustment to a line that no longer exists is not a fact about anything.
 * And the only line that can be deleted is a draft one, because
 * `payroll_lines.payroll_run_id` cascades from a run that only a policy-approved
 * *draft* deletion can remove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_line_adjustments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payroll_line_id')->index()->constrained()->cascadeOnDelete();

            /*
             * SIGNED — decimal(12, 3) without `unsigned()`. A deduction is a
             * negative number here, and that is the whole point of the column:
             * a bonus and a deduction are the same operation with a different
             * sign, not two mechanisms.
             *
             * Never float, never two places.
             */
            $table->decimal('amount', 12, 3);

            /*
             * NOT NULL. An adjustment with no explanation is a figure nobody can
             * defend at the end of the month, and the mandatory reason is the
             * only thing that separates this from an unexplained change to
             * somebody's wage.
             */
            $table->text('reason');

            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });

        /*
         * An adjustment of zero is a reason with no effect, and it would still
         * appear in the audit trail as though something had happened — design
         * section 7 makes the same argument for the signed amount on a
         * correction line.
         *
         * `<>` rather than a positivity check: negative is not merely allowed
         * here, it is half of what the column is for.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_line_adjustments
            ADD CONSTRAINT payroll_line_adjustments_amount_not_zero
            CHECK (amount <> 0)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraint with it.
        Schema::dropIfExists('payroll_line_adjustments');
    }
};
