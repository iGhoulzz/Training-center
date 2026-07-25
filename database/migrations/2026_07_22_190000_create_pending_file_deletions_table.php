<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durability record for a file that must be removed from disk.
 *
 * WHY THIS TABLE EXISTS
 * ---------------------
 * Filesystem operations do not participate in database transactions. A
 * Storage::delete() inside DB::transaction() deletes immediately, so a
 * subsequent rollback leaves a surviving row pointing at bytes that are already
 * gone — unrecoverable. The order therefore has to be commit first, delete
 * after.
 *
 * That ordering opens a second hole: a worker that dies between the commit and
 * the delete loses the file silently and forever. Writing a row here, inside the
 * same transaction that removes the owning record, closes it. The intent to
 * delete is committed atomically with the deletion itself, and the actual
 * unlink is a retryable job that runs afterwards.
 *
 * A row that lingers here is a RECONCILABLE ORPHAN: the bytes still exist and
 * the record of what to do with them still exists, so a sweep can re-dispatch
 * it. That is the failure mode this design deliberately chooses, because the
 * alternative — an orphaned file nobody knows about — is personal data the
 * centre has no grounds to hold and no way to find.
 *
 * Not staff-specific by design. Phase 2 receipts and phase 3 student
 * certificates reuse the same private disk and will reuse this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_file_deletions', function (Blueprint $table): void {
            $table->id();

            // Which disk holds the bytes, recorded rather than assumed, exactly
            // as staff_certificates.disk is. A row that does not say where its
            // file lives cannot be reconciled.
            $table->string('disk', 30);

            // Matches staff_certificates.path and staff_profiles.profile_photo_path.
            $table->string('path', 512);

            // How many times the purge job has failed for this row. Read by
            // operators, not by the retry mechanism — Laravel owns the retry
            // count; this survives the job's own lifetime.
            $table->unsignedInteger('attempts')->default(0);

            // The most recent failure, recorded so a stuck row explains itself
            // without needing the failed_jobs payload.
            $table->text('last_error')->nullable();

            $table->timestamps();

            // A sweep asks "what has been pending longer than the threshold",
            // which is an ordered range scan on created_at.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_file_deletions');
    }
};
