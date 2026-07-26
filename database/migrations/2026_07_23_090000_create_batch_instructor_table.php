<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who teaches a batch, and for how many hours (P1-T10).
 *
 * THE HOURS BELONG TO THE RELATIONSHIP
 * ------------------------------------
 * Spec section 6. Two instructors can share one batch, so hours cannot live on
 * the batch (which side would 18 belong to?) nor on the user (which batch?).
 * They live on the pair. One instructor on a 30-hour batch takes all 30; two may
 * split 18/12; and two genuinely co-teaching may BOTH be assigned 30, because
 * both are present throughout. The sum may therefore legitimately exceed the
 * batch total — the system warns and never blocks.
 *
 * user_id IS restrictOnDelete, DELIBERATELY
 * -----------------------------------------
 * Phase 2 pays wages from these rows. An instructor holding allocations must
 * not be hard-deletable, because destroying the row destroys the record of work
 * that was done and may still be owed for. Accounts soft delete in this system;
 * a forceDelete against an instructor with hours is refused by the database
 * itself, which is the one place the refusal cannot be raced or reasoned around.
 *
 * batch_id is cascadeOnDelete because an allocation to a batch that no longer
 * exists is not a fact about anything — it is a dangling row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_instructor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            /*
             * unsignedSmallInteger: 0..65535. A batch measured in whole hours
             * never approaches the ceiling, and the column cannot hold the
             * negative value that "hours owed back" would imply — which is not
             * a concept this system has.
             *
             * Default 0 so that a row created without an explicit figure means
             * "assigned, hours not yet decided" rather than NULL, which would
             * make every SUM() nullable for no benefit.
             */
            $table->unsignedSmallInteger('assigned_hours')->default(0);
            $table->timestamps();

            /*
             * One row per instructor per batch. This is what makes reassignment
             * an UPDATE rather than a second row: without it, correcting Sara's
             * hours from 18 to 24 would leave two rows summing to 42 and
             * phase 2 would pay her for both.
             */
            $table->unique(['batch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_instructor');
    }
};
