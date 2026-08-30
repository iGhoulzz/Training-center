<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONE VALID CERTIFICATE PER ENROLMENT, held at the database (design section
 * 6.2), the same technique `payroll_lines` uses for its two paid-once rules
 * (`finalized_batch_instructor_id`, `finalized_salary_segment`).
 *
 * ```sql
 * valid_enrollment_id BIGINT UNSIGNED
 *     GENERATED ALWAYS AS (CASE WHEN status = 'valid' THEN enrollment_id END) STORED,
 * UNIQUE KEY uniq_valid_certificate_per_enrollment (valid_enrollment_id)
 * ```
 *
 * MySQL's unique index does not collide on NULL, and the generated expression
 * carries a value ONLY while `status = 'valid'`:
 *
 *   - a `valid` row carries its own `enrollment_id`, so a second `valid` row
 *     for the same enrolment collides on this index and is refused;
 *   - a `replaced` or `revoked` row carries NULL, so any number of them coexist
 *     for one enrolment, and a `valid` row can be superseded and superseded
 *     again without ever running out of room.
 *
 * ONE ALTER STATEMENT, DELIBERATELY
 * -----------------------------------
 * Adding a stored generated column rebuilds the table; adding its unique index
 * in the SAME statement means one rebuild rather than two, and — the reason
 * this migration exists on its own — one point of failure rather than a second
 * schema statement that could commit while a first one in the same file did
 * not. See the first migration's docblock for why the three-way split exists at
 * all.
 *
 * THIS IS NOT DECORATION FOR A CASE THAT COULD BE HANDLED IN PHP
 * ------------------------------------------------------------------
 * A seeder, a console command or a repair script writing a second `valid` row
 * bypasses every Action and every PHP-level check `IssueStudentCertificateAction`
 * and `ReplaceStudentCertificateAction` (T5) will ever perform. Only a
 * constraint that MySQL itself enforces closes that path, and
 * `CertificateSchemaTest` proves it with a raw `DB::table()->insert()` that
 * goes nowhere near either Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE student_certificates
            ADD COLUMN valid_enrollment_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'valid' THEN enrollment_id END
                ) STORED,
            ADD UNIQUE INDEX uniq_valid_certificate_per_enrollment (valid_enrollment_id)
        SQL);
    }

    public function down(): void
    {
        // The index before the column: MySQL refuses to drop a column while an
        // index still covers it, and — being one ALTER statement — dropping
        // both together is exactly as atomic as adding them together was.
        DB::statement(<<<'SQL'
            ALTER TABLE student_certificates
            DROP INDEX uniq_valid_certificate_per_enrollment,
            DROP COLUMN valid_enrollment_id
        SQL);
    }
};
