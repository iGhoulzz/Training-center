<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index users.name, which P1-T10 orders by (P1-T10c).
 *
 * docs/ENGINEERING.md: "Index every foreign key, and every column used in
 * WHERE or ORDER BY." Two orderings introduced by the instructor-hours task
 * need this one and neither had it:
 *
 *   - InstructorsRelationManager::eligibleInstructors() — the assign modal's
 *     Select, `->orderBy('name')`. It runs on every open of the modal.
 *   - The instructors panel's `name` column, which is ->sortable(); clicking
 *     the header orders the relation by users.name.
 *
 * Additive rather than an edit to the create-users migration, which has already
 * run in the development database — an edited migration would silently not
 * apply there while looking applied.
 *
 * This is an ORDER BY index, not a search index. `name` is also ->searchable()
 * in these tables, and Filament's searchable() builds LIKE %term%, which a
 * B-tree cannot seek on at all (see ENGINEERING.md on courses.name_ar). The
 * sorting is what justifies the index; the searching neither justifies it nor
 * benefits from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['name']);
        });
    }
};
