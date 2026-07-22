<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch is a course actually running: the January intake, the March intake.
 *
 * `total_hours` is the load-bearing nullable column. It is null by default and
 * INHERITS from the parent course at read time — see
 * Batch::$effective_total_hours. It is not copied at creation, deliberately:
 * a copy is a second source of truth that goes stale the moment
 * someone corrects the course, and nothing would ever tell you it had.
 *
 * The literal 'planned' default is deliberately not BatchStatus::Planned->value.
 * A migration that has run is never edited, so it must not depend on application
 * code that may later be renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table): void {
            $table->id();

            /*
             * restrictOnDelete, NOT cascade, and this is the choice the whole
             * table hangs on. A batch carries the centre's record of who was
             * taught what and when, and P1-T11 hangs enrolments off it.
             * Deleting a course must therefore be REFUSED while any of its
             * batches exist, loudly, rather than silently destroying that
             * history along with the catalogue entry.
             *
             * The refusal lives here rather than in CoursePolicy because a
             * policy check races: a batch can be created between the check
             * passing and the delete running. A foreign key cannot be raced.
             */
            $table->foreignId('course_id')->constrained()->restrictOnDelete();

            // The centre's own identifier for this intake ("ENG-B1-JAN26"),
            // quoted at the desk and printed on schedules. Longer than the
            // course code because it usually contains it.
            $table->string('code', 40)->unique();

            // Nullable: an intake can be pencilled in before its dates are
            // fixed, which is exactly what the planned status is for.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Enrolling past capacity produces a warning, not a hard block
            // (spec section 6), so this is a planning figure rather than a
            // constraint. Zero means nobody has set one yet.
            $table->unsignedSmallInteger('capacity')->default(0);

            // NULL MEANS INHERIT from the parent course. Not zero, and not a
            // copy of the course value — see the class docblock above.
            $table->unsignedSmallInteger('total_hours')->nullable();

            /*
             * PHASE 2 COLUMN. Created now so that phase 2 never has to ALTER a
             * table already holding production data, and deliberately invisible
             * until then: it appears in no form, no table column and no report.
             * BatchTest pins both the precision and the invisibility.
             *
             * Nullable, but NOTHING inherits it in phase 1 — no code reads
             * this column at all. What a null price means is phase 2's
             * decision, taken once the charge model exists.
             *
             * decimal(12, 3), never float and never two places. The currency is
             * LYD, which subdivides into 1000 dirham per ISO 4217, so two
             * decimal places would silently round every dirham-precision amount
             * and break reconciliation the moment money arrives.
             */
            $table->decimal('price', 12, 3)->nullable();

            // Indexed on its own because the dashboard's default view is "what
            // is running now", which filters on status alone.
            $table->string('status', 30)->default('planned')->index();

            $table->timestamps();

            // The other common read is "the open intakes of this course", asked
            // every time someone is enrolled. A composite index in this order
            // serves that and a course_id-only lookup; the single-column status
            // index above serves the cross-course view.
            $table->index(['course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
