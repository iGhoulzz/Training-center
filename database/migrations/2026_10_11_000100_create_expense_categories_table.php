<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The managed list an expense is recorded against (phase 4, spec section 6).
 *
 * Admins maintain it, so adding a category like "Insurance" needs no developer.
 *
 * A CATEGORY IS DEACTIVATED, NEVER DELETED
 * ----------------------------------------
 * `is_active` is the one lifecycle column. A deactivated category leaves the
 * picker a new expense is recorded against and stays on every expense that
 * already carries it. `expenses.expense_category_id` restricts on delete as a
 * backstop, but no delete path exists to reach it: the policy refuses and no
 * Filament control is registered.
 *
 * THE ENGLISH NAME IS UNIQUE, IN THE DATABASE
 * ---------------------------------------------
 * Two "Rent" headings would make the picker a guess. The Actions validate it for a
 * friendly message, but a check that only reads the table lets two concurrent saves
 * both pass, so the unique index is the backstop and the Actions turn a lost race
 * into the same field error. The default collation is case-insensitive, which is
 * the behaviour wanted: "Rent" and "rent" collide. `expense_categories_name_en_unique`
 * is Laravel's generated index name, and the Actions match on it. `name_ar` has no
 * index: it is nullable and optional.
 *
 * `name_ar` is nullable because Arabic arrives with the phase 4 catalogues and a
 * category is usable before anyone has translated it; readers fall back to the
 * English name, as courses do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name_en', 100)->unique();
            $table->string('name_ar', 100)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
