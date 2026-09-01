<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table) {
            $table->string('path_kind', 30)->default('file')->after('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table) {
            $table->dropColumn('path_kind');
        });
    }
};
