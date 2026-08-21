<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('locale', 10);
            $table->string('student_code', 64);
            $table->string('student_name');
            $table->string('enrollment_reference', 64);
            $table->string('course_code', 64);
            $table->string('batch_code', 64);
            $table->string('charge_reference', 64);
            $table->decimal('list_price', 12, 3);
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('final_charge', 12, 3);
            $table->decimal('amount_paid', 12, 3);
            $table->decimal('remaining_balance', 12, 3);
            $table->string('recorded_by_name');
            $table->string('payment_reference', 64);
            $table->timestamp('received_at');
            $table->timestamp('last_reconciliation_attempt_at')->nullable()->index();
            $table->timestamps();

            $table->index('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_receipt_snapshots
            ADD CONSTRAINT payment_receipt_snapshots_amounts_non_negative
            CHECK (
                list_price >= 0
                AND final_charge >= 0
                AND amount_paid >= 0
                AND remaining_balance >= 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_receipt_snapshots
            ADD CONSTRAINT payment_receipt_snapshots_discount_percentage_valid
            CHECK (
                discount_percentage IS NULL
                OR (discount_percentage > 0 AND discount_percentage <= 100)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipt_snapshots');
    }
};
