<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of 4: add the unique index on `reference`, and nothing else.
 *
 * It comes after the backfill, not before. Indexing an empty column first would
 * mean the backfill races the constraint it is trying to satisfy, and a
 * uniqueness failure mid-UPDATE leaves the table half-filled.
 *
 * This is also where the previous revision of the design went wrong: it paired
 * the index with the nullability change, which Laravel compiles into two ALTER
 * statements run one after the other. MySQL commits DDL as it goes, so a
 * failure on the second leaves the index created, and the retry dies on a
 * duplicate index name — the unrecoverable state the split exists to prevent.
 * Nothing else belongs in this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
        });
    }
};
