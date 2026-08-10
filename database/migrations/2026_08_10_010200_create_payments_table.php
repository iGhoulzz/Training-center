<?php

declare(strict_types=1);

use App\Domain\Finance\Support\Reference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One handover of money, and the receipt the student takes away.
 *
 * THERE IS NO amount, NO method AND NO status COLUMN
 * --------------------------------------------------
 * Design section 5. The total is `SUM(payment_tenders.amount)`, because a single
 * payment can be settled 300 by card and 700 in cash and one `method` column
 * cannot represent the split-tender checkout every retail counter performs.
 * Payment state derives from `reversed_at` alone.
 *
 * **Because no draft state exists, every row in this table is by definition
 * finalized.** Payment, tenders and allocations are written in one transaction
 * (design section 5), so a half-finished payment cannot exist to be misread
 * later. Nothing should look for a finalized flag here; there is none, and
 * adding one would be a derived value.
 *
 * `payments.reversed_at` is read as raw SQL by `ChargeBalance` — it is the only
 * thing that takes a reversed payment's allocations out of every balance and
 * every revenue figure. Renaming it compiles fine and fails at runtime, on
 * money.
 *
 * THE STUDENT IS DERIVED, NEVER SUPPLIED
 * --------------------------------------
 * `student_id` is resolved by `RecordPaymentAction` from the locked charge,
 * through `EnrollmentQueryService`. The Action's DTO has no student field at
 * all, so a crafted request cannot attach a payment to one student while
 * settling another's bill — there is nothing to attach (design section 5). The
 * column exists because every report and the per-student history read it
 * directly; it is not an input.
 *
 * TWO COLUMNS ARE WRITTEN AFTER CREATION, AND THEY ARE NOT FINANCIAL FACTS
 * -----------------------------------------------------------------------
 * `receipt_disk` and `receipt_path`. The receipt is rendered asynchronously
 * after commit, so its location cannot be known when the payment is written.
 * They are set once by `AttachReceiptAction`, whose sole caller is
 * `GenerateReceiptJob` (design sections 5 and 10). The immutability this table
 * claims is about what the payment *says happened*; a pointer to a rendered
 * document is not part of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // Restricts, like every financial foreign key. Students soft-delete,
            // so this only fires on forceDelete — the operation that would
            // actually destroy the record of money taken.
            $table->foreignId('student_id')->index()->constrained()->restrictOnDelete();

            /*
             * `RCT-2026-000042` — the receipt number, read aloud down a phone.
             * NOT NULL UNIQUE, satisfied by the placeholder-then-update inside
             * one transaction described in design section 2.
             *
             * Sized from Reference::COLUMN_LENGTH, not from the 15 characters a
             * real reference needs: the widest value the column ever holds is a
             * placeholder. Referencing the constant is what stops the migration
             * and the generator from agreeing by coincidence.
             */
            $table->string('reference', Reference::COLUMN_LENGTH)->unique();

            /*
             * RETRY PROTECTION. A client-generated UUID minted when the
             * collection form is first rendered and submitted with the payment.
             * A double-clicked button is otherwise two payments and two receipts
             * for one handover of cash, and both submissions are individually
             * valid — nothing else in the design catches it (design section 5).
             *
             * NOT NULL, deliberately. MySQL's unique index permits any number of
             * NULLs, so a nullable key would let the one mechanism that prevents
             * a duplicate payment evaporate silently for any caller that omitted
             * it. Every payment comes through `RecordPaymentAction`, which mints
             * or receives one; a row without a key is a bug, and this is where
             * it stops.
             *
             * 64 rather than 36: a UUID is 36, and the headroom costs nothing
             * while leaving the unique index comfortably inside MySQL's key
             * length. The Action matches on THIS INDEX NAME —
             * `payments_idempotency_key_unique`, Laravel's generated form — when
             * it converts the violation into a replay, exactly as
             * `EnrollStudentAction` already does. Renaming it breaks that catch.
             */
            $table->string('idempotency_key', 64)->unique();

            /*
             * The canonical fingerprint of the request that produced this
             * payment: the charge id, the allocation amount, and each tender's
             * method, amount and trimmed terminal reference, in a stable order
             * (design section 5). `received_at` and `notes` are excluded — one
             * is not caller-supplied, the other decorates rather than moves
             * money.
             *
             * On a key collision the Action compares fingerprints: identical is
             * a genuine replay and returns the existing payment; different means
             * the same key is being used for a different request and raises
             * `IdempotencyConflictException`. Returning whatever payment owns
             * the key would hand back an unrelated receipt for money that was
             * never recorded.
             *
             * NOT indexed. It is never searched on — the lookup is always by
             * `idempotency_key`, and this column is only ever compared against
             * the one row that index found.
             *
             * 64 characters sizes a hex SHA-256, which is the obvious digest and
             * the one this column was sized for. **Task 4 chooses the hashing
             * mechanism**; if it picks a wider digest, this is the column to
             * widen, and it must not store the canonical payload itself.
             */
            $table->string('request_fingerprint', 64);

            /*
             * When the money changed hands. Generated by `RecordPaymentAction`
             * at the moment it records the payment — no form field, DTO field or
             * request parameter sets it, which is why a replay keeps the
             * original timestamp and a reprinted receipt is identical to the one
             * first handed over.
             *
             * Indexed because every report converts a local calendar period in
             * Africa/Tripoli into a half-open UTC range and compares
             * `received_at >= start AND received_at < end` (design section 8).
             * A range comparison rather than date extraction is precisely so
             * this index is usable; wrapping the column in a conversion function
             * would turn every report into a table scan.
             */
            $table->timestamp('received_at')->index();

            // The staff member at the desk. Restricts: an account that has taken
            // money is not hard-deletable.
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();

            $table->text('notes')->nullable();

            /*
             * REVERSAL — a set-once lifecycle transition on an otherwise
             * immutable row. Super admin only, via `reverse_payment`. The
             * payment's financial facts (student, reference, tenders,
             * allocations) are never rewritten and never deleted; there is no
             * delete path for a payment at all.
             *
             * `reversed_at` is not indexed. Almost every row is null, so a
             * B-tree on it has nothing selective to seek, and the filter is
             * always applied alongside a `received_at` range or a primary-key
             * join — `ChargeBalance` reaches it by `payments.id` and then tests
             * this column on the one row it found.
             */
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();

            /*
             * Where the rendered receipt lives on the private disk. Nullable
             * because the job that renders it runs after commit. 512 matches
             * `staff_profiles.profile_photo_path`; the disk name is a config key,
             * not a path.
             */
            $table->string('receipt_disk', 50)->nullable();
            $table->string('receipt_path', 512)->nullable();

            $table->timestamps();
        });

        /*
         * All three reversal columns or none. A reversal with no actor or no
         * reason is not a state this table should be able to hold, and the
         * refusal belongs in the database rather than only in
         * `ReversePaymentAction` — an Action's check protects the paths that go
         * through it, a constraint protects the rest.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_reversal_columns_paired
            CHECK (
                (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its CHECK constraint with it.
        Schema::dropIfExists('payments');
    }
};
