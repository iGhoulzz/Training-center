<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();

            /*
             * BOTH FOREIGN KEYS RESTRICT, AND NEITHER CASCADES.
             *
             * From phase 2 an enrolment carries charges and payments; cascading
             * either delete would destroy financial history as a side effect of
             * tidying up a student or a batch. Restricting turns that into a
             * refusal the application can explain.
             *
             * students soft-delete, so student_id's restriction only fires on
             * forceDelete — the operation that would actually lose the rows.
             * batches do not soft-delete, so batch_id's restriction is what
             * DeleteBatchAction converts into BatchInUseException.
             */
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();

            $table->timestamp('enrolled_at');
            $table->string('status', 30)->default('active')->index();

            /*
             * Written by phase 3, never by phase 1. Spec line 71 assigns
             * completion marking to phase 3; the column exists now because the
             * spec's schema (line 215) names it and adding it later would be a
             * migration against a table phase 2 is already billing from.
             *
             * There is deliberately NO withdrawn_at. Withdrawal is terminal and
             * unambiguous from the status alone, and a second timestamp would be
             * a value that has to be kept consistent with it forever.
             *
             * NO certificate_issued_at either. Phase 3 records each issued
             * physical certificate as its own immutable student_certificates row
             * so revocation and replacement history survives; a timestamp here
             * could record only the most recent issuance and would erase the
             * previous one on reissue. See spec section 6.
             */
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The rule that "a student is on a batch once", enforced where it
            // cannot be raced. The Action's own check produces the readable
            // message; this is what holds when something writes around it.
            $table->unique(['student_id', 'batch_id']);

            // Serves Batch::activeEnrollmentCount() and the panel's status filter.
            $table->index(['batch_id', 'status']);

            // Serves the panel's default ordering, which sorts a batch's
            // enrolments by when they were made. Without it that ordering is a
            // filesort over every row for the batch.
            $table->index(['batch_id', 'enrolled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
