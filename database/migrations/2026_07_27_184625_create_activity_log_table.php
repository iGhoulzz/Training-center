<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            /*
             * The log is read newest-first and filtered by date range, and it is
             * the one table here that only ever grows. Without this the panel's
             * default sort and every date filter scan the whole table, which is
             * invisible on a seeded database and ruinous after a year of
             * production writes.
             *
             * The date filter compares timestamps rather than calling whereDate(),
             * so it can actually use this index — wrapping the column in DATE()
             * makes the comparison non-sargable and the index unusable.
             */
            $table->index('created_at');
        });
    }

    /**
     * Dropping the table discards the audit trail, which is why the append-only
     * rules exist — but a migration without a down() cannot be rolled back at
     * all, and `migrate:fresh` on a development database is a normal thing to do.
     * The guarantee this task makes is that the APPLICATION cannot remove an
     * entry; it was never that the schema is irreversible.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
