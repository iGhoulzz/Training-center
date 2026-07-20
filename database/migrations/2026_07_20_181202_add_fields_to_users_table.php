<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->after('email');
            $table->boolean('is_active')->default(true)->index()->after('locale');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['locale', 'is_active', 'must_change_password', 'last_login_at']);
        });
    }
};
