<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batch_instructor.batch_id: cascadeOnDelete becomes restrictOnDelete.
 *
 * SUPERSEDES THE RATIONALE IN create_batch_instructor_table.
 *
 * That migration reasoned that "an allocation to a batch that no longer exists is
 * not a fact about anything". It is: it is the record that somebody was down to
 * teach those hours, which is exactly what phase 2 pays wages from — the same
 * reason user_id on that table is already restrictOnDelete. Protecting the row
 * from one side and cascading it from the other left the guarantee half-made.
 *
 * P1-T11 makes batch deletion a refusable operation rather than a silent one:
 * enrollments.batch_id restricts, DeleteBatchAction converts the refusal into a
 * readable message, and this migration puts the instructor allocations under the
 * same protection. A batch carrying either enrolments or instructor hours is now
 * undeletable, and the refusal comes from the database, where it cannot be raced.
 *
 * Dropping and re-adding is required: MySQL cannot alter a foreign key's
 * referential action in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_instructor', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->foreign('batch_id')->references('id')->on('batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_instructor', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->foreign('batch_id')->references('id')->on('batches')->cascadeOnDelete();
        });
    }
};
