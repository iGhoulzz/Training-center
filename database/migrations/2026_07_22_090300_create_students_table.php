<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student is a record of a person the centre teaches, not a login.
 *
 * Most students never sign in to anything: they are enrolled at the desk, they
 * attend, they graduate. `user_id` is therefore nullable, and phase 3's portal
 * fills it in for the minority who do get an account — without altering this
 * table or touching a single existing row.
 *
 * The literal 'prospective' default is deliberately not
 * StudentStatus::Prospective->value. A migration that has run is never edited,
 * so it must not depend on application code that may later be renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();

            /*
             * nullOnDelete, NOT cascade — this is the load-bearing choice in
             * the whole table. A portal account is a convenience bolted onto a
             * student record; the student is the thing the centre actually
             * keeps. Deleting the login must sever the link and leave the
             * record, its history, and its enrolments standing. cascade here
             * would silently destroy a student's entire file the day someone
             * tidied up an unused account.
             *
             * unique() gives both the 1:1 constraint and the foreign key's
             * index. MySQL permits many NULLs in a unique index, so every
             * accountless student coexists happily.
             */
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // The centre's own identifier, printed on paperwork and quoted at
            // the desk. Unique because it is what humans search by.
            $table->string('student_code', 30)->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100);

            // Nullable and indexed: contact details are how staff find someone
            // who cannot remember their code, but the centre enrols walk-ins
            // who leave a phone number and nothing else.
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('national_id', 50)->nullable()->index();

            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->text('address')->nullable();

            // Filtered on constantly (the active roster), so indexed, with a
            // database-level default so a row inserted outside the application
            // still lands in a known state.
            $table->string('status', 30)->default('prospective')->index();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Students soft delete. A withdrawn student's record is still
            // referenced by enrolments, receipts, and certificates, so the row
            // leaves the roster without leaving the database.
            $table->softDeletes();

            // The register is listed and searched by name, surname first. A
            // composite index in that order serves both the sort and a
            // last-name-only lookup; two single-column indexes would serve
            // neither well.
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
