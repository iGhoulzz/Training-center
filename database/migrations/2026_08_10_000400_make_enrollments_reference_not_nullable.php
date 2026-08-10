<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4 of 4: tighten `reference` to NOT NULL, and nothing else.
 *
 * Last, because every row must already hold a value before the column can
 * refuse an absent one. With steps 1 to 3 applied, the column reaches the state
 * design section 2 requires of every reference series: NOT NULL UNIQUE.
 *
 * Written as a raw ALTER rather than `->change()`. `doctrine/dbal` is not a
 * dependency of this project and is not being added for one column, and the raw
 * form has the property this whole split is about: it is unambiguously one
 * statement, whose exact DDL is visible to a reviewer. Laravel's `change()`
 * also silently drops any column attribute not restated, so it would need the
 * type spelled out here regardless.
 *
 * VARCHAR(64) restates step 1's type exactly. MySQL's MODIFY redefines the
 * column rather than amending it, so a mismatch here would quietly resize it.
 * The column carries no default, comment, or explicit collation, so there is
 * nothing further to restate; its charset follows the table default, as it did
 * when step 1 created it.
 *
 * Re-running this is safe: modifying an already-NOT NULL column to NOT NULL is
 * a no-op that MySQL accepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE enrollments MODIFY reference VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enrollments MODIFY reference VARCHAR(64) NULL');
    }
};
