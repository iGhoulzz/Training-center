<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors a schedule rule into the database, and indexes what is sortable.
 *
 * `docs/ENGINEERING.md` requires that validation which matters is mirrored by a
 * database constraint, and that every column used for ordering is indexed.
 * Both were missing:
 *
 *   - A batch could be written with end_date before start_date. Form validation
 *     does not cover a seeder, a console command, or an Action, and a backwards
 *     batch silently corrupts every schedule and duration figure built on it.
 *   - start_date and name_en were offered as sort columns with no index behind
 *     them, so ordering meant a filesort over the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL 8.0.16+ enforces CHECK. Both dates are nullable, and any
        // comparison against NULL yields NULL rather than false, so a batch
        // with one date or no dates still satisfies the constraint.
        DB::statement(<<<'SQL'
            ALTER TABLE batches
            ADD CONSTRAINT batches_end_date_not_before_start_date
            CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)
        SQL);

        Schema::table('batches', function (Blueprint $table): void {
            $table->index('start_date');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->index('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['name_en']);
        });

        Schema::table('batches', function (Blueprint $table): void {
            $table->dropIndex(['start_date']);
        });

        DB::statement('ALTER TABLE batches DROP CONSTRAINT batches_end_date_not_before_start_date');
    }
};
