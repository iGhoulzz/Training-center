<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a person is paid, and for which stretch of time.
 *
 * EFFECTIVE-DATED: A RAISE INSERTS A ROW, IT NEVER UPDATES ONE
 * -----------------------------------------------------------
 * This is a project non-negotiable, not a preference. `ChangeCompensationAction`
 * closes the previous row and inserts the new one in a single transaction; there
 * is no update path at all. `update_staff_compensation` is deliberately not
 * seeded and `StaffCompensationPolicy::update()` refuses even if it is granted,
 * because the only legitimate change to a rate is a new row (design sections 7
 * and 10). `create_staff_compensation` is therefore the write ability for this
 * table, and there is no update counterpart.
 *
 * The reason is payroll history. A March payroll run recomputed in June must
 * still produce March's figures, and a rate that was overwritten cannot do that.
 *
 * `per_student` IS GONE
 * ---------------------
 * Types are `salary` (a monthly amount) and `hourly` (a per-hour rate), and one
 * person may hold both — a salaried administrator who also teaches. The centre
 * does not pay per head, and design section 15 records the removal so it is not
 * reintroduced as an oversight.
 *
 * WHAT PREVENTS OVERLAPPING PERIODS IS NOT IN THIS FILE
 * ----------------------------------------------------
 * No `CHECK` and no unique index can express "no two rows for this person and
 * type overlap in range". `CompensationPeriodInvariantService` does it with a
 * lock **on the `users` row**, not on these rows — because when a person has no
 * compensation rows yet there is nothing here to lock, and two concurrent
 * first-row writes would both see an empty table and both pass. Locking the
 * stable parent serializes them (design section 7).
 *
 * That is why design section 14 requires the concurrency test to start from zero
 * existing rows: that is the state in which the naive implementation passes.
 *
 * The table name is singular-ish on purpose — `staff_compensation`, as design
 * section 9 names it. Every foreign key to it must therefore pass the table name
 * explicitly, because Laravel's convention would guess `staff_compensations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_compensation', function (Blueprint $table): void {
            $table->id();

            /*
             * Restricts. An account with a compensation history is not
             * hard-deletable: destroying the rows would destroy the explanation
             * for every payroll line that froze a rate from them.
             *
             * No fluent index — the composite below leads on user_id and
             * therefore already serves this key, as `enrollments.batch_id` is
             * served by `enrollments_batch_id_status_index`.
             */
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            /*
             * 'salary' or 'hourly', cast to a backed enum on the model.
             * Indexed per design section 9: a payroll run selects one type at a
             * time — a monthly_salary run reads only salary rows, an
             * instructor_batch run only hourly ones.
             *
             * No default. There is no type a rate falls back to, and guessing
             * wrong pays somebody a monthly salary as an hourly rate.
             */
            $table->string('type', 30)->index();

            /*
             * The monthly amount or the per-hour rate, depending on `type`.
             * decimal(12, 3) — never float, never two places.
             */
            $table->decimal('amount', 12, 3);

            /*
             * The validity range. Dates rather than timestamps: a rate applies
             * from a day, and the salary segmentation in design section 7
             * intersects this range with calendar months, which is whole-day
             * arithmetic in the local calendar.
             *
             * A null `effective_to` is the open current row — the one in force.
             * `ChangeCompensationAction` closes it as it inserts the next.
             */
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            /*
             * The one query every payroll run makes: this person's rows of this
             * type, ordered by when they took effect, so the run can intersect
             * them with its period. Leading on user_id also serves the foreign
             * key's index and a plain "everything for this person" lookup.
             */
            $table->index(['user_id', 'type', 'effective_from']);
        });

        /*
         * Nobody is paid nothing, and a negative rate would be a deduction —
         * which is a `payroll_line_adjustments` row, not a compensation row.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE staff_compensation
            ADD CONSTRAINT staff_compensation_amount_positive
            CHECK (amount > 0)
        SQL);

        /*
         * A range that ends before it begins produces segments of negative
         * length, and every wage figure computed from them is wrong in a way
         * that looks arithmetically fine. The open row is unaffected: NULL
         * satisfies the first disjunct.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE staff_compensation
            ADD CONSTRAINT staff_compensation_effective_to_not_before_from
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints with it.
        Schema::dropIfExists('staff_compensation');
    }
};
