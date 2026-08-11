<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable percentage the centre is willing to give — "10%", "Staff family 30%".
 *
 * WHY THE DEFINITION HAS NO EDIT PATH, AND NO COLUMN SAYS SO
 * ----------------------------------------------------------
 * Design section 3 makes a definition immutable from creation: name and
 * percentage are set once. There is no `UpdateDiscountAction`, no `is_locked`
 * column and no `CHECK` guarding one, because the absence of a write path
 * enforces it for free. The alternative considered — "immutable once referenced"
 * — was rejected: it would mean a definition changed shape depending on whether
 * anyone had used it yet.
 *
 * Getting one wrong therefore has exactly two paths, and **which one applies is
 * decided by this schema rather than by a rule**. Before any charge references
 * it, delete it and create the one you meant. After a charge references it,
 * `charges.discount_id` restricts the delete and MySQL refuses (error 1451,
 * which `DeleteDiscountAction` converts into a typed refusal); the correct move
 * is then to deactivate and create a replacement.
 *
 * THE PERCENTAGE IS COPIED ONTO THE CHARGE, NOT READ THROUGH IT
 * -------------------------------------------------------------
 * `charges.discount_percentage` freezes the figure at issue. This row is the
 * catalogue entry; the bill records what was actually applied. Deactivating or
 * replacing a definition therefore moves no issued bill.
 *
 * WHY THE CHECK IS A `DB::statement()` AND WHY IT IS NAMED
 * -------------------------------------------------------
 * Laravel's schema builder has no `CHECK` helper, so every constraint in this
 * phase is a raw `ALTER TABLE` run immediately after the table is created, in
 * the same migration — the pattern
 * `2026_07_22_171220_add_schedule_constraints_to_batches_and_courses.php`
 * already established. MySQL 8.0.16+ enforces them.
 *
 * **Every constraint is named explicitly** rather than left to MySQL's
 * `tablename_chk_1` autonumbering. Design section 14 requires each `CHECK` to be
 * proven by an insert that violates it, and a test that has to guess at
 * `discounts_chk_1` breaks the moment a constraint is added above it.
 *
 * The DDL-does-not-roll-back rule that split the `enrollments.reference`
 * migrations into four does not apply here: this migration creates its own
 * table, so a failure part way through is recovered by dropping the table and
 * re-running, which is exactly what `down()` does.
 *
 * NAME IS SINGLE-LANGUAGE, DELIBERATELY
 * -------------------------------------
 * Unlike `courses.name_en` / `name_ar`, a discount carries one `name`. Design
 * section 9 specifies it that way, and this is operator-entered data rather than
 * a user-facing string the application ships — phase 4 translates the interface,
 * not the centre's own records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();

            // Unique because the name is what an operator picks from at the
            // desk; two "Staff family" rows would make the choice a guess.
            $table->string('name', 150)->unique();

            /*
             * decimal(5, 2): 0.01 to 100.00, and two places is what `Money`
             * carries a percentage in — hundredths of a percent, so 33.33% is
             * an exact integer there. A percentage is not money and is
             * deliberately not decimal(12, 3): the rounding rule in design
             * section 3 multiplies this by a price and rounds the *result* to
             * integer dirham, so a third decimal place here would buy nothing
             * and would fall outside what `Money` can read exactly.
             */
            $table->decimal('percentage', 5, 2);

            // The one lifecycle transition, owned by DeactivateDiscountAction.
            // Indexed because the selector renders the active set on every
            // enrolment. Deactivating changes nothing about an issued charge.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        /*
         * A zero discount is not a discount and a negative one is a surcharge,
         * which this system does not have. 100 is allowed: a fully waived
         * enrolment is a real thing the centre does, and it produces a 0.000
         * charge rather than no charge at all.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE discounts
            ADD CONSTRAINT discounts_percentage_within_range
            CHECK (percentage > 0 AND percentage <= 100)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraint with it.
        Schema::dropIfExists('discounts');
    }
};
