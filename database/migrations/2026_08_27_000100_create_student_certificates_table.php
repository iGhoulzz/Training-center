<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The certificate register (design section 6.1). One row per PHYSICAL
 * certificate ever issued, never per enrolment: a replacement or a revocation
 * both add a row rather than mutate the one before it, so the whole issuance
 * history survives even though only one row may be `valid` at a time.
 *
 * THREE MIGRATIONS, NOT ONE
 * --------------------------
 * This file creates the table and nothing else. The generated column plus its
 * unique index is a migration of its own, and the two named CHECK constraints
 * are a third — MySQL does not roll back DDL, so a migration carrying two
 * schema statements can fail on the second having already committed the
 * first, leaving a retry that dies on the first. Phase 2's `enrollments`
 * reference split (`EnrollmentReferenceBackfillTest`'s docblock) is the
 * precedent this follows.
 *
 * NO PDF, TEMPLATE, IMAGE, DISK OR PATH COLUMN
 * -----------------------------------------------
 * This table is the register of WHAT WAS ISSUED, not a document store. Nothing
 * in phase 3 renders or stores the physical artefact; T5's Actions and T7/T8's
 * pages work entirely from the columns declared here.
 *
 * `student_name`, `course_name` AND `completed_on` ARE A SNAPSHOT
 * -------------------------------------------------------------------
 * Frozen at issuance, matching the physical certificate the student was handed,
 * and deliberately do NOT follow a later correction to the student or course
 * record — the same "frozen at issue" reasoning `charges.list_price` and
 * `charges.discount_percentage` already carry for phase 2's bills. Renaming a
 * course after certificates have gone out must not silently rewrite documents
 * already in students' hands.
 *
 * WHY THE FOREIGN KEYS RESTRICT
 * -------------------------------
 * `enrollment_id`, `issued_by`, `revoked_by` and the self-referencing
 * `replaces_certificate_id` all restrict on delete, matching every other
 * financial-or-adjacent foreign key in this schema (see `charges`,
 * `payroll_lines`): an issued certificate is never destroyed as the side
 * effect of tidying up an enrolment, a user account or another certificate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_certificates', function (Blueprint $table): void {
            $table->id();

            // Not unique — unlike charges' one-bill-per-enrolment column, an
            // enrolment accumulates certificate HISTORY: one valid row plus any
            // number of replaced and revoked ones. The one-valid-per-enrolment
            // rule is the next migration's generated column, which can only be
            // expressed once `status` exists on the row.
            $table->foreignId('enrollment_id')->index()->constrained()->restrictOnDelete();

            /*
             * `TC-{year}-{8 random characters}`, minted by CertificateReference
             * before the row is inserted — unlike the `ENR-`/`CHG-`/`RCT-`
             * series, this value does not depend on the row's own id, so there
             * is no placeholder-then-replace dance to make room for (see
             * App\Domain\Finance\Support\Reference, and CertificateReference's
             * own docblock for why that trick is deliberately not repeated
             * here).
             *
             * 20, not the 16 the format always produces (`TC-` + 4-digit year +
             * `-` + 8 characters). A literal rather than a class constant,
             * matching every other frozen reference-width migration in this
             * schema: a migration that has run is never edited, so it must not
             * depend on application code a later rename could change
             * underneath it.
             */
            $table->string('reference_number', 20)->unique();

            // The issuance snapshot. 255 for the name: students.first_name and
            // .last_name are 100 each, so `trim("{$first} {$last}")` can reach
            // 201 characters, and 255 leaves headroom without pretending to be
            // a calculation. 200 for the course name mirrors courses.name_en
            // exactly, since this is a straight copy of it at issuance.
            $table->string('student_name', 255);
            $table->string('course_name', 200);
            $table->date('completed_on');

            // When this row was written — server time, set by the issuing
            // Action, never user-supplied. A timestamp rather than a date:
            // unlike completed_on, which records a calendar day, issuance is an
            // instant and CertificateReference's year is read from it (design
            // section 6.5).
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->index()->constrained('users')->restrictOnDelete();

            // valid | revoked | replaced. 30 and a default of 'valid' match
            // every other status column in this schema (students, batches,
            // enrollments) even though this value set is narrower — the width
            // is a convention, not a measurement of this column alone. The
            // value set itself is enforced by a named CHECK in the third
            // migration, not by this column definition: MySQL has no native
            // enum-of-strings constraint that produces a constraint name a test
            // can assert against, and an unnamed one is exactly what design
            // section 14 forbids.
            $table->string('status', 30)->default('valid')->index();

            /*
             * The certificate THIS row replaced, if it was issued as a
             * reprint. Self-referencing, nullable, and UNIQUE: a replaced
             * certificate can be superseded by at most one later row, which is
             * what stops two independent reprints both claiming the same
             * predecessor. unique() supplies both that guarantee and the
             * foreign key's index, the same shape `charges.enrollment_id`
             * uses.
             *
             * The FORWARD pointer lives on the NEW row, not the old one — see
             * design section 6.4: "the new row's replaces_certificate_id
             * points at it". A column added to the OLD row instead would have
             * to be written after the new row's id exists, which is exactly
             * the ordering hazard `enrollments.reference` used to have before
             * the placeholder trick.
             */
            $table->foreignId('replaces_certificate_id')->nullable()->unique()
                ->constrained('student_certificates')->restrictOnDelete();

            // The revocation, when there is one. All three arrive together or
            // not at all — chk_student_certificates_revocation, added in the
            // third migration, is what makes that true at the database rather
            // than merely in whichever form collects it.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_certificates');
    }
};
