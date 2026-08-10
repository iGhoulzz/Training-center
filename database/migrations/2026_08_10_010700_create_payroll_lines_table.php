<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a payroll run, in one of three shapes, and the two generated
 * columns that stop the centre paying the same thing twice.
 *
 * THE THREE SHAPES (design section 9)
 * -----------------------------------
 *   salary      staff_compensation_id · segment_start · segment_end ·
 *               frozen_rate · frozen_days · frozen_days_in_month
 *   instructor  staff_compensation_id · batch_instructor_id · frozen_rate ·
 *               frozen_hours
 *   adjustment  corrects_payroll_line_id · signed computed_amount · reason
 *
 * A `CHECK` enforces that exactly one shape is present. It cannot verify that
 * the shape matches its run's type, because a MySQL `CHECK` may not reference
 * another table — `FinalizePayrollRunAction` does that inside the finalizing
 * transaction. **The constraint guarantees a line is internally coherent; only
 * the Action can guarantee it belongs where it sits.**
 *
 * A SALARY LINE IS A SEGMENT, NOT A PERIOD
 * ----------------------------------------
 * A run may legitimately cover a partial previous month plus a full current one.
 * Each line is therefore the intersection of the run's period, one calendar
 * month, and one compensation row's validity range, and it freezes its own
 * denominator: `round(rate × days ÷ days_in_month)` in integer dirham.
 *
 * Splitting by calendar month is not cosmetic — a salary pro-rated across a
 * boundary has **two different denominators**, and one line spanning 20 March to
 * 15 April cannot express both. Splitting by rate is what makes a mid-period
 * raise produce two correctly-priced lines instead of one averaged wrong one.
 * Design revision 1 keyed uniqueness on `(user_id, period_start,
 * staff_compensation_id)`, a model that cannot represent the owner's actual
 * case.
 *
 * AN INSTRUCTOR LINE FREEZES THE HOURS AS WELL AS THE RATE
 * -------------------------------------------------------
 * `frozen_hours` copies `batch_instructor.assigned_hours` at finalization and
 * `computed_amount = frozen_rate × frozen_hours`. Design revision 2 dropped the
 * hours and had to restore them: without them, an allocation edited after
 * payment leaves a finalized amount that nothing in the database can explain.
 * For a wage record, right and unjustifiable is the same as wrong — design
 * section 14 tests exactly that, by changing `assigned_hours` underneath a
 * finalized line.
 *
 * WHAT ACTUALLY PREVENTS PAYING TWICE
 * -----------------------------------
 * Two stored generated columns with unique indexes, added below. Both carry
 * their value **only while `finalized_at` is set**, so drafts collide with
 * nothing and a draft can be rebuilt as often as the operator likes.
 *
 * `finalized_at` is denormalized onto the line at finalization — frozen data,
 * like every other payroll figure — because a generated column cannot reference
 * another table and so cannot read the run's.
 *
 * **Overlapping salary segments are NOT caught here, and design section 7 says
 * so honestly.** Two runs covering 1–15 January and 10–31 January produce
 * segments with different start dates, and no unique index refuses them. The
 * database catches exact duplicates; only `FinalizePayrollRunAction`'s lock and
 * range check catch overlaps, and that protects the application path alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->id();

            /*
             * CASCADE, and this is the one financial foreign key that does.
             * Lines are genuine children of their run — a line whose run does
             * not exist is not a fact about anything — and **only a draft run is
             * ever deletable**: `PayrollRunPolicy` refuses a finalized one
             * regardless of `delete_payroll_run` (design sections 9 and 10). So
             * the cascade can only ever discard draft lines.
             */
            $table->foreignId('payroll_run_id')->index()->constrained()->cascadeOnDelete();

            // Who the line pays. Restricts: an account with payroll history is
            // not hard-deletable.
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();

            /*
             * The rate row this line froze its figures from. Nullable because an
             * adjustment line has none — it is a signed correction, not a
             * calculation.
             *
             * The table name must be passed explicitly: design section 9 names
             * it `staff_compensation`, and Laravel's convention would guess
             * `staff_compensations`.
             */
            $table->foreignId('staff_compensation_id')->nullable()->index()
                ->constrained('staff_compensation')->restrictOnDelete();

            /*
             * The instructor-hour assignment this line pays. Nullable — only an
             * instructor line has one — and the value the first generated column
             * below carries once the line is finalized.
             *
             * Restricts: the assignment is the evidence for the amount, and
             * design section 14 requires a finalized line to still explain
             * itself after `assigned_hours` is changed underneath it. It cannot
             * do that if the row it froze from can be deleted.
             *
             * Table name passed explicitly — `batch_instructor` is a singular
             * pivot and the convention would guess `batch_instructors`.
             */
            $table->foreignId('batch_instructor_id')->nullable()->index()
                ->constrained('batch_instructor')->restrictOnDelete();

            /*
             * The line this one corrects. Self-referencing, nullable, and only
             * an adjustment line has it.
             *
             * Restricts, so a correction can never be orphaned from what it
             * corrects. Corrections are flat and never chained — design section
             * 7 forbids a correction targeting another correction, enforced by
             * `AdjustPayrollLineAction` because a CHECK cannot follow a
             * reference to another row. Multiple corrections against the same
             * original are allowed and sum.
             */
            $table->foreignId('corrects_payroll_line_id')->nullable()->index()
                ->constrained('payroll_lines')->restrictOnDelete();

            /*
             * The segment this salary line covers, always inside one calendar
             * month. Null on instructor and adjustment lines — which is also
             * what the second generated column below uses to recognise a salary
             * line without reading its run.
             */
            $table->date('segment_start')->nullable();
            $table->date('segment_end')->nullable();

            /*
             * NULLABLE, and this is deliberate rather than lax. An adjustment
             * line has no rate — it is a signed correction, and forcing a value
             * here would mean storing a meaningless number in a column whose
             * whole purpose is to explain how an amount was reached (design
             * section 9).
             */
            $table->decimal('frozen_rate', 12, 3)->nullable();

            /*
             * The instructor's assigned hours, copied at finalization.
             * unsignedSmallInteger to mirror `batch_instructor.assigned_hours`
             * exactly — a frozen copy that could hold a value its source cannot
             * would be a copy of nothing.
             */
            $table->unsignedSmallInteger('frozen_hours')->nullable();

            /*
             * The salary segment's numerator and denominator: days in the
             * segment, and days in the calendar month it falls inside. Both fit
             * a tiny integer several times over, because a segment never spans a
             * month boundary — that is the whole reason segments exist.
             *
             * The denominator is frozen alongside the numerator so a February
             * segment stays divided by 28 or 29 forever, without the report
             * having to re-derive which.
             */
            $table->unsignedTinyInteger('frozen_days')->nullable();
            $table->unsignedTinyInteger('frozen_days_in_month')->nullable();

            /*
             * What this line is worth. SIGNED — decimal(12, 3) without
             * `unsigned()` — because an adjustment line carries a signed
             * correction and a deduction is a real one. Never float, never two
             * places.
             */
            $table->decimal('computed_amount', 12, 3);

            /*
             * THE PERIOD THIS LINE'S COST BELONGS TO. For a salary line the
             * segment's month; for an instructor line the run's finalization
             * month; **for an adjustment line, copied from the line being
             * corrected** — which is what makes a June correction land in
             * March's wage cost (design section 7).
             *
             * NULLABLE, and paired with `finalized_at` by the CHECK below: set on
             * exactly the rows that are finalized, null on exactly the rows that
             * are not. A draft line never carries it — an instructor draft's
             * posting month is the month it will eventually be finalized in,
             * which is unknown while it is still a draft, and design section 9
             * requires the pairing rather than merely allowing it. Design
             * revision 3 declared this column non-nullable outright, which made
             * every draft line unwritable and the whole draft-review step
             * impossible; nullable-but-paired is what keeps drafts writable
             * without letting one carry a period report queries would then have
             * to know to exclude.
             *
             * Indexed because every period report groups on it. It also serves
             * as the access path for "finalized lines in this period": a line
             * only carries a posting period once it is finalized, so this index
             * already selects the finalized set.
             */
            $table->date('posting_period_start')->nullable()->index();

            // Mandatory on an adjustment line, by the shape CHECK below. The
            // reason a correction exists is the only thing that explains it.
            $table->text('reason')->nullable();

            /*
             * Frozen onto the line at finalization rather than read from the
             * run, because the generated columns below need it on this row —
             * a generated column may not reference another table.
             *
             * Not separately indexed: `posting_period_start` is non-null exactly
             * when this is set, and its index is the one every report uses.
             */
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();
        });

        /*
         * EXACTLY ONE SHAPE, spelled out from design section 9's table.
         *
         * Each branch states both what the shape carries and what it must leave
         * null, and the three are mutually exclusive by construction:
         * `batch_instructor_id` separates salary from instructor, and
         * `corrects_payroll_line_id` separates the correction from both. That
         * mutual exclusivity is what lets an OR mean "exactly one" — without it
         * this would only mean "at least one", which is a much weaker promise
         * than the design makes.
         *
         * `reason` is required on a correction (design section 7 calls it
         * mandatory) and deliberately left unconstrained on the other two:
         * section 9's table does not list it among what they must null, and a
         * salary line carrying an explanatory note is not a defect.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_lines
            ADD CONSTRAINT payroll_lines_exactly_one_shape
            CHECK (
                (
                    staff_compensation_id IS NOT NULL
                    AND segment_start IS NOT NULL
                    AND segment_end IS NOT NULL
                    AND frozen_rate IS NOT NULL
                    AND frozen_days IS NOT NULL
                    AND frozen_days_in_month IS NOT NULL
                    AND batch_instructor_id IS NULL
                    AND corrects_payroll_line_id IS NULL
                    AND frozen_hours IS NULL
                )
                OR (
                    staff_compensation_id IS NOT NULL
                    AND batch_instructor_id IS NOT NULL
                    AND frozen_rate IS NOT NULL
                    AND frozen_hours IS NOT NULL
                    AND segment_start IS NULL
                    AND segment_end IS NULL
                    AND frozen_days IS NULL
                    AND frozen_days_in_month IS NULL
                    AND corrects_payroll_line_id IS NULL
                )
                OR (
                    corrects_payroll_line_id IS NOT NULL
                    AND reason IS NOT NULL
                    AND staff_compensation_id IS NULL
                    AND batch_instructor_id IS NULL
                    AND segment_start IS NULL
                    AND segment_end IS NULL
                    AND frozen_rate IS NULL
                    AND frozen_hours IS NULL
                    AND frozen_days IS NULL
                    AND frozen_days_in_month IS NULL
                )
            )
        SQL);

        /*
         * A FINALIZED LINE HAS A POSTING PERIOD. A DRAFT NEVER DOES.
         *
         * A biconditional — `(finalized_at IS NULL) = (posting_period_start IS
         * NULL)` — the shape the paired reversal and finalization columns use
         * elsewhere in this schema. Design section 9 is explicit and reads as a
         * pairing, not a one-way implication: "It is nullable, indexed, and
         * required only once `finalized_at` is set — a `CHECK` pairs them. A
         * draft line **cannot** carry it."
         *
         * An earlier revision of this migration read that prose as permissive —
         * "required only once finalized" taken to mean a draft merely need not
         * carry one, not that it must not — and shipped the implication
         * `finalized_at IS NULL OR posting_period_start IS NOT NULL`. That let a
         * draft persist with a posting period, on the theory that a salary
         * draft's posting month is knowable the moment its segment is computed.
         * It is knowable, but knowable is not the same as stored: the column is
         * what every period report selects the finalized set through — see this
         * migration's own comment on the column above — so a draft carrying one
         * leaks unposted wage cost into a report that groups on it. The design's
         * own words settle this the other way, and this constraint now says what
         * they say.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_lines
            ADD CONSTRAINT payroll_lines_posting_period_required_when_finalized
            CHECK ((finalized_at IS NULL) = (posting_period_start IS NULL))
        SQL);

        /*
         * AN INSTRUCTOR ASSIGNMENT IS PAID AT MOST ONCE.
         *
         * The column is `batch_instructor_id` while the line is finalized and
         * NULL otherwise, and MySQL's unique index ignores NULLs. So:
         *   - a draft line carries NULL and collides with nothing, which is what
         *     lets a draft be rebuilt and re-ticked freely;
         *   - a salary or adjustment line has no assignment, so it too carries
         *     NULL;
         *   - two finalized lines for the same assignment carry the same value
         *     and the second insert is refused by the database.
         *
         * Everything the expression needs is on the row — `finalized_at` was
         * denormalized here precisely so this could be written. It touches no
         * other table and no AUTO_INCREMENT column, which are the two things
         * MySQL forbids a generated column from doing.
         *
         * STORED rather than VIRTUAL because a unique index over a virtual
         * column is not what design section 7 specified, and a stored column is
         * what makes the index a plain B-tree over materialised values.
         *
         * Written with DB::statement() because the column and its index are one
         * ALTER: adding a stored generated column rebuilds the table, and doing
         * the index in the same statement means one rebuild rather than two.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_lines
            ADD COLUMN finalized_batch_instructor_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN finalized_at IS NOT NULL THEN batch_instructor_id END
                ) STORED,
            ADD UNIQUE INDEX payroll_lines_finalized_batch_instructor_unique (finalized_batch_instructor_id)
        SQL);

        /*
         * THE IDENTICAL SALARY SEGMENT IS NOT FINALIZED TWICE.
         *
         * One composite value carrying `(user_id, segment_start)`, and only while
         * the line is finalized AND is a salary line. `segment_start IS NOT NULL`
         * is what identifies a salary line from the row alone: the shape CHECK
         * above nulls the segment columns on both other shapes, so the two
         * conditions cannot disagree.
         *
         * The parts are joined with ':' and the date is cast explicitly. The
         * result is unambiguous because `user_id` is digits only and the date is
         * always exactly ten characters, so no pair of (id, date) can produce the
         * same string as another. VARCHAR(64) is generous: the widest possible
         * value is a 20-digit id, a separator and a 10-character date.
         *
         * CASE with no ELSE yields NULL, and MySQL's unique index ignores NULLs —
         * so drafts, instructor lines and corrections all collide with nothing.
         *
         * This catches EXACT duplicates only. Overlapping segments with different
         * start dates are refused by `FinalizePayrollRunAction`'s lock and range
         * check, never by this index; design section 7 states that separately and
         * honestly, and so does this comment.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_lines
            ADD COLUMN finalized_salary_segment VARCHAR(64)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN finalized_at IS NOT NULL AND segment_start IS NOT NULL
                            THEN CONCAT(user_id, ':', CAST(segment_start AS CHAR))
                    END
                ) STORED,
            ADD UNIQUE INDEX payroll_lines_finalized_salary_segment_unique (finalized_salary_segment)
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraints, its generated columns
        // and their unique indexes with it.
        Schema::dropIfExists('payroll_lines');
    }
};
