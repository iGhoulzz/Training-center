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

            $table->string('name_en', 100);
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
