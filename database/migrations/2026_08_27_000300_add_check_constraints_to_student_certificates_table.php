<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two named CHECK constraints design section 6.2 requires, added together as
 * one ALTER TABLE statement — see the first migration's docblock for why this
 * table's DDL is split across three files.
 *
 * BOTH CONSTRAINTS DEVIATE FROM THE SQL THE DESIGN PRINTS, AND THE DEVIATION IS
 * THE POINT (P3-T04)
 * ============================================================================
 * The design and the plan both specify
 * `status IN ('valid','revoked','replaced')` and
 * `CHAR_LENGTH(TRIM(revocation_reason)) > 0`. Measured against this project's
 * MySQL 8.4, neither enforces what it claims. Both were corrected with the
 * owner's approval; the measurements are recorded here so nobody "restores" the
 * documented form.
 *
 * `chk_student_certificates_status` — COLLATE utf8mb4_bin
 * ---------------------------------------------------------
 * The database collation is `utf8mb4_unicode_ci`, which is CASE-INSENSITIVE:
 *
 *     SELECT 'Valid' IN ('valid','revoked','replaced');   -- 1, not 0
 *
 * So the documented constraint would not have refused `'Valid'` at all, and the
 * plan's own acceptance criterion — "a row inserted with status = 'Valid' is
 * refused" — was unachievable as written. `COLLATE utf8mb4_bin` makes the
 * comparison byte-exact:
 *
 *     SELECT 'Valid' COLLATE utf8mb4_bin IN ('valid','revoked','replaced');  -- 0
 *     SELECT 'valid' COLLATE utf8mb4_bin IN ('valid','revoked','replaced');  -- 1
 *
 * The design's stated RATIONALE was wrong in the same direction and is corrected
 * here too. It says a mistyped `'Valid'` "yields NULL in valid_enrollment_id and
 * slips past the unique index". It does not: the generated column reads
 * `status = 'valid'`, which is equally case-insensitive, so `'Valid'` populates
 * the column and collides on the index like any other valid row. The slip-past
 * hazard is real for a genuine typo — `'vaild'`, an invented fifth value — which
 * is what this constraint actually catches, and what its test uses alongside the
 * case variant.
 *
 * `chk_student_certificates_revocation` — REGEXP, not TRIM
 * -----------------------------------------------------------
 * A plain `NOT NULL` admits `''`, so a reason "mandatory" in the form is absent
 * in the database. `CHAR_LENGTH(TRIM(...)) > 0` was the documented fix and it is
 * not enough — MySQL's single-argument `TRIM` strips ASCII SPACES ONLY:
 *
 *     SELECT CHAR_LENGTH(TRIM('   '));   -- 0, refused
 *     SELECT CHAR_LENGTH(TRIM('	'));    -- 1, ACCEPTED
 *     SELECT CHAR_LENGTH(TRIM('
'));    -- 1, ACCEPTED
 *
 * This repository has been bitten by exactly that before: a finance CHECK
 * carried a comment claiming TRIM rejected blanks, and a tab passed. That is why
 * `payment_tenders`' card-reference constraint uses a REGEXP, and this one now
 * does the same:
 *
 *     revocation_reason REGEXP '[^[:space:]]'
 *
 * — at least one character that is not whitespace of any kind. Measured: tab,
 * newline and a run of spaces all rejected; `' misconduct '` accepted.
 *
 * `revocation_reason IS NOT NULL` SITS IN FRONT OF THAT REGEXP, and it is not
 * redundant. A CHECK constraint in MySQL passes when its condition evaluates to
 * NULL rather than FALSE, and `NULL REGEXP '...'` is NULL — so a revoked row
 * with a NULL reason satisfied the constraint and inserted cleanly. The
 * documented `CHAR_LENGTH(TRIM(revocation_reason)) > 0` has the identical hole
 * (`NULL > 0` is NULL), so this was never a consequence of moving to REGEXP.
 *
 * Found by CertificateSchemaTest's missing-field dataset, which is the only
 * reason it is not still there.
 *
 * `status COLLATE utf8mb4_bin = 'revoked'` inside this constraint for the same
 * reason as above: without it, a row with `status = 'Revoked'` would take the
 * second branch — which demands the revocation fields be NULL — and a genuinely
 * revoked certificate could be stored with no actor, time or reason.
 *
 * CertificateSchemaTest proves each of these directly rather than assuming the
 * expressions behave as described.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE student_certificates
            ADD CONSTRAINT chk_student_certificates_status
                CHECK (status COLLATE utf8mb4_bin IN ('valid', 'revoked', 'replaced')),
            ADD CONSTRAINT chk_student_certificates_revocation
                CHECK (
                    (status COLLATE utf8mb4_bin = 'revoked'
                        AND revoked_at IS NOT NULL
                        AND revoked_by IS NOT NULL
                        AND revocation_reason IS NOT NULL
                        AND revocation_reason REGEXP '[^[:space:]]')
                    OR (status COLLATE utf8mb4_bin <> 'revoked'
                        AND revoked_at IS NULL
                        AND revoked_by IS NULL
                        AND revocation_reason IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE student_certificates
            DROP CONSTRAINT chk_student_certificates_status,
            DROP CONSTRAINT chk_student_certificates_revocation
        SQL);
    }
};
