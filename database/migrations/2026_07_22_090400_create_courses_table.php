<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course is the catalogue entry, not the thing that runs.
 *
 * "English B1" is defined once here and then run as many times as the centre
 * likes; each intake is a row in `batches`. That split is the whole reason this
 * table exists separately — without it the January and March intakes would be
 * two copies of the same definition, and correcting a syllabus description
 * would mean finding every copy.
 *
 * Nothing here is derived. `total_hours` is the catalogue default a batch
 * inherits when it does not state its own; the inheritance lives in the query,
 * not in a copied column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();

            // The centre's own catalogue identifier ("ENG-B1"), printed on
            // brochures and quoted on the phone. Unique because it is what
            // humans use to mean a specific course.
            $table->string('code', 30)->unique();

            // Bilingual from commit one, though the Arabic strings arrive in
            // phase 4. name_ar is nullable because a course may be added before
            // anyone has translated it; Course::name() falls back to English.
            $table->string('name_en', 200);
            $table->string('name_ar', 200)->nullable();

            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();

            // The catalogue default. A batch with a null total_hours inherits
            // this value at read time — see Batch::$effective_total_hours.
            $table->unsignedSmallInteger('total_hours')->default(0);

            /*
             * PHASE 2 COLUMN. Created now so that phase 2 never has to ALTER a
             * table already holding production data, and deliberately invisible
             * until then: it appears in no form, no table column and no report.
             * CourseTest pins both the precision and the invisibility.
             *
             * decimal(12, 3), never float and never two places. The currency is
             * LYD, which subdivides into 1000 dirham per ISO 4217, so two
             * decimal places would silently round every dirham-precision amount
             * and break reconciliation the moment money arrives.
             */
            $table->decimal('default_price', 12, 3)->default(0);

            // Retired courses stay in the catalogue because past batches and
            // their enrolment history still point at them. Indexed because the
            // catalogue is filtered to the active set on nearly every read.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
