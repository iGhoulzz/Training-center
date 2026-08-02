<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the reconciliation sweep last handed this receipt to a purge job.
 *
 * WHY A COLUMN RATHER THAN NOTHING
 * --------------------------------
 * The bounded sweep first selected its page purely by created_at, which never
 * changes. A page that never drains — a disk that is gone, an ownership check
 * that keeps finding an owner — is therefore selected again by every subsequent
 * run, and the receipts behind it are never dispatched at all. The bound stops
 * being a rate limit and becomes a permanent ceiling over the rest of the table,
 * which is the opposite of what bounding it was for.
 *
 * Nothing already on the row can fix that. created_at is immutable by design,
 * and attempts is written by the JOB rather than by the sweep, so it says
 * nothing about whether this receipt has had its turn — a receipt whose job
 * never ran has attempts 0 forever.
 *
 * NULL MEANS THE SWEEP HAS NEVER TOUCHED IT, and those sort ahead of every
 * swept receipt regardless of age. That is the anti-starvation property: one
 * pass reaches the whole backlog before any receipt gets a second turn, and
 * rotation continues from there. See PendingFileDeletion::scopeInSweepOrder().
 *
 * It is deliberately NOT the same fact as "a purge job was dispatched for this
 * receipt". The ordinary lifecycle dispatches one at creation and records
 * nothing here; this column belongs to the sweep alone, which is why it is named
 * for the sweep.
 *
 * NO INDEX, DELIBERATELY. The eligibility filter is
 * `created_at < T AND (last_swept_at IS NULL OR last_swept_at < T)`, and the
 * existing created_at index already carries the range. An index on this column
 * cannot serve the OR, and cannot serve the ordering either, because the
 * nulls-first term is an expression. It would be write cost with no read
 * benefit. The ordering costs a filesort over the stale rows, which is
 * negligible for a table whose healthy size is a handful of transient receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table): void {
            $table->timestamp('last_swept_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table): void {
            $table->dropColumn('last_swept_at');
        });
    }
};
