<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_profiles.user_id: cascadeOnDelete becomes restrictOnDelete.
 *
 * SUPERSEDES THE RATIONALE IN create_staff_profiles_table.
 *
 * That migration reasoned that "a profile is a genuine child record with no
 * meaning once its account is gone". True of the ROW, and irrelevant to the
 * FILES, which is what makes the cascade dangerous (P1-T15, domain-integrity
 * finding 4).
 *
 * WHAT THE CASCADE ACTUALLY DID
 * -----------------------------
 * staff_certificates.staff_profile_id also cascades, so one hard delete of a
 * user removed the profile and every certificate row beneath it in a single
 * database operation. A cascade fires no Eloquent event, so:
 *
 *   - no pending_file_deletions receipt was written for any of those files, and
 *   - the rows naming those files were gone before anything could read them.
 *
 * The bytes therefore stayed on disk — scanned national IDs and identity
 * documents — with nothing left in the database that could ever identify them.
 * Not an orphan the sweep can reconcile: the receipt table is the only record of
 * what needs destroying, and the cascade skipped writing it while destroying the
 * only other source of the paths.
 *
 * WHY RESTRICT RATHER THAN A CODE-LEVEL CHECK
 * -------------------------------------------
 * The constraint forces the caller through DeleteStaffProfileAction, which reads
 * every certificate path and the photo path BEFORE the cascade precisely so the
 * bytes can be collected. Expressing that as a precheck instead would be
 * raceable; expressing it as a foreign key is not. enrollments.batch_id and
 * batch_instructor.batch_id already restrict for the same reason.
 *
 * NOTHING REACHABLE CHANGES TODAY. UserPolicy::forceDelete() returns false and
 * no control is registered anywhere, so no user-facing path performs a hard
 * delete. That is an argument for adding the constraint now rather than for
 * deferring it: a trusted administrative operation is still entitled to fail
 * loudly instead of quietly destroying data, and the next person to register a
 * ForceDeleteAction is the one who would otherwise spring it.
 *
 * The profile-to-certificate cascade is deliberately LEFT IN PLACE. It is safe
 * exactly because an Action always stands in front of it — which is the
 * guarantee this migration adds.
 *
 * Dropping and re-adding is required: MySQL cannot alter a foreign key's
 * referential action in place. The unique index on user_id is created
 * separately by unique() and survives the drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
