<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of 4: add `reference` as a nullable column, and nothing else.
 *
 * The `ENR-` series is described in section 2 of the phase 2 financials design.
 * Adding the column, backfilling it, indexing it and tightening it are four
 * migrations because MySQL does not roll back DDL: a migration holding two
 * schema statements can fail on the second having already committed the first,
 * and the retry then dies on the first. One schema statement per migration is
 * the only form of this that survives a partial failure.
 *
 * Nullable here is not the intended end state — step 4 tightens it. It is the
 * only state a populated table will accept, because adding a NOT NULL column
 * with no default to existing rows fails outright, and there is no default that
 * could be correct when the value is a function of the row's own id.
 *
 * The length is 64 rather than the 15 a generated `ENR-2026-000042` needs.
 * Rows are inserted carrying a UUID placeholder that is replaced with the real
 * reference inside the same transaction (design section 2), and a UUID is 36
 * characters; 64 holds either with room for a prefixed placeholder, while
 * keeping step 3's unique index small.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('reference', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('reference');
        });
    }
};
