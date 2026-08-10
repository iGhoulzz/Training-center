<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which bill a payment settled, and by how much. Every balance in the system is
 * a sum of these rows.
 *
 * **Staff never see the word "allocation" and never make an allocation
 * decision** (design section 2). The phase 2 UI always targets exactly one bill,
 * so the allocation is decided by the context the operator opened. The
 * many-charge capability stays in the schema anyway, so a future "pay both my
 * courses at once" flow needs no migration — design section 13 confirms that as
 * a deliberate, accepted cost.
 *
 * THIS TABLE IS READ AS RAW SQL
 * -----------------------------
 * `ChargeBalance` names `payment_allocations.amount`, `.charge_id` and
 * `.payment_id` in a correlated subquery, joined to `payments` for
 * `reversed_at`, because an Eloquent relationship cannot be handed to
 * `orderBy()`. Those names cannot be refactored by an IDE. Renaming one compiles
 * fine and fails at runtime, on a balance.
 *
 * Design section 6 puts aggregation in SQL for the same reason this table has no
 * cached total anywhere: MySQL's DECIMAL sums are exact, and summing
 * `decimal:3` strings in PHP converts them to float, where 0.001 — the dirham —
 * is exactly the digit that is lost.
 *
 * BOTH FOREIGN KEYS RESTRICT
 * --------------------------
 * An allocation is the link between money taken and a debt discharged. Deleting
 * either end would leave a balance that silently changed with no record of why,
 * so neither end is deletable while this row exists. A payment is reversed, not
 * deleted; a bill is removed only while it is uncommitted, which
 * `DeleteUncommittedChargeAction` defines as carrying no allocation at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();

            // No fluent index: the composite unique below leads on payment_id
            // and therefore already serves this key, exactly as
            // `enrollments.student_id` does under
            // `enrollments_student_id_batch_id_unique`. A second index on the
            // same prefix would cost every write and never be chosen.
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();

            // index() before constrained(), so the "index every foreign key"
            // rule is visible in the source. This is also the index
            // ChargeBalance's correlated subquery seeks on, once per charge.
            $table->foreignId('charge_id')->index()->constrained()->restrictOnDelete();

            // decimal(12, 3). Never float, never two places.
            $table->decimal('amount', 12, 3);

            $table->timestamps();

            /*
             * One allocation row per payment per bill. Without it, a payment
             * could be recorded against the same charge twice and both rows
             * would sum into the balance — the "tender total equals allocation
             * total" invariant would still hold on each write while the bill
             * quietly over-settled.
             *
             * Leading on payment_id so it also serves that foreign key; see the
             * note on that column.
             */
            $table->unique(['payment_id', 'charge_id']);
        });

        /*
         * Strictly positive. A zero allocation discharges nothing, and a
         * negative one is a refund — ruled out of the whole system by design
         * section 1. There is deliberately no upper bound here: "never more than
         * outstanding" is derived from other rows under a lock, which a CHECK
         * cannot express (design section 11).
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payment_allocations
            ADD CONSTRAINT payment_allocations_amount_positive
            CHECK (amount > 0)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraint with it.
        Schema::dropIfExists('payment_allocations');
    }
};
