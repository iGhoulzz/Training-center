<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support PurgeDeletedFileJob's current locking ownership reads.
 *
 * This is additive rather than an edit to the Task 6 create-table migrations:
 * those migrations have already run in the development database. Fresh tests
 * and upgraded databases must receive the same indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->index('profile_photo_path');
        });

        Schema::table('staff_certificates', function (Blueprint $table): void {
            $table->index(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_certificates', function (Blueprint $table): void {
            $table->dropIndex(['disk', 'path']);
        });

        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->dropIndex(['profile_photo_path']);
        });
    }
};
