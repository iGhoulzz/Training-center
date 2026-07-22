<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment attributes describe a person's job, not their login, so they live
 * beside `users` rather than on it: a super admin may have no employment
 * record, and a departed instructor keeps theirs after the account is
 * deactivated.
 *
 * The literal 'administrative' default is deliberately not
 * EmploymentType::Administrative->value. A migration that has run is never
 * edited, so it must not depend on application code that may later be renamed
 * or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();

            // unique() gives both the 1:1 constraint and the foreign key's
            // index. cascade is correct here: a profile is a genuine child
            // record with no meaning once its account is gone.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('phone', 30)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->date('hire_date')->nullable();

            // Filtered on (instructors vs. everyone else), so indexed.
            $table->string('employment_type', 30)->default('administrative')->index();

            // Free text on purpose: "PhD in Applied Linguistics, University of
            // Tripoli". Credentials are recorded for reference, never filtered
            // or reported on, so an enum would only force a migration every
            // time a real qualification did not fit.
            $table->text('qualifications')->nullable();

            // A path only. No default image is stored per user — the UI renders
            // initials when this is null (see StaffProfile::initials()).
            $table->string('profile_photo_path', 512)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
