<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

/*
|--------------------------------------------------------------------------
| Each certificate migration applies and rolls back on its own (P3-T04)
|--------------------------------------------------------------------------
|
| WHY THE SPLIT EXISTS, AND THEREFORE WHY THIS FILE DOES.
|
| MySQL does not roll back DDL. A migration carrying two schema statements can
| fail on the second having already committed the first, and the retry then dies
| on the first — an unrecoverable state that needs hand repair on a production
| database. That is why the register's DDL is three files rather than one.
|
| A split nobody exercises is a claim, not a property: CertificateSchemaTest runs
| only against the fully migrated schema under RefreshDatabase and would pass
| just as happily if all three files were merged, or if a down() were wrong or
| missing. These call the up() and down() paths directly and assert the
| intermediate states, following EnrollmentReferenceBackfillTest's pattern.
*/

/** The three steps, in order, by migration name. */
const CERTIFICATE_MIGRATIONS = [
    1 => '2026_08_27_000100_create_student_certificates_table',
    2 => '2026_08_27_000200_add_valid_enrollment_id_to_student_certificates_table',
    3 => '2026_08_27_000300_add_check_constraints_to_student_certificates_table',
];

/**
 * One migration, as an object whose up() and down() can be called.
 *
 * `require`, not `require_once`: each file ends in
 * `return new class extends Migration`, so every evaluation yields a fresh
 * instance — which is how Laravel's own Migrator loads them. `require_once`
 * would return `true` on the second call and this helper would hand back a
 * boolean.
 */
function certificateMigration(int $step): Migration
{
    /** @var Migration $migration */
    $migration = require base_path('database/migrations/'.CERTIFICATE_MIGRATIONS[$step].'.php');

    return $migration;
}

/** Is the named CHECK constraint on the table right now? */
function certificateCheckExists(string $name): bool
{
    return DB::table('information_schema.TABLE_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'student_certificates')
        ->where('CONSTRAINT_NAME', $name)
        ->exists();
}

/** Is the one-valid-certificate unique index on the table right now? */
function certificateValidIndexExists(): bool
{
    return DB::table('information_schema.STATISTICS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'student_certificates')
        ->where('INDEX_NAME', 'uniq_valid_certificate_per_enrollment')
        ->exists();
}

afterEach(function () {
    /*
     * LOAD-BEARING, not tidiness. Each test leaves the schema wherever its
     * scenario needed it; this puts it back where the `migrations` table claims
     * it is, so DatabaseMigrations can roll everything back normally afterwards.
     *
     * Every step is guarded rather than blindly re-run, because re-adding an
     * existing column or constraint is an error rather than a no-op.
     */
    if (! Schema::hasTable('student_certificates')) {
        certificateMigration(1)->up();
    }

    if (! Schema::hasColumn('student_certificates', 'valid_enrollment_id')) {
        certificateMigration(2)->up();
    }

    if (! certificateCheckExists('chk_student_certificates_status')) {
        certificateMigration(3)->up();
    }

    foreach (CERTIFICATE_MIGRATIONS as $name) {
        if (! DB::table('migrations')->where('migration', $name)->exists()) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
    }
});

it('rolls back the CHECK constraints without touching the rest of the schema', function () {
    certificateMigration(3)->down();

    expect(certificateCheckExists('chk_student_certificates_status'))->toBeFalse()
        ->and(certificateCheckExists('chk_student_certificates_revocation'))->toBeFalse()
        // The step below it is untouched — that is what "independently" means.
        ->and(Schema::hasColumn('student_certificates', 'valid_enrollment_id'))->toBeTrue()
        ->and(certificateValidIndexExists())->toBeTrue();

    certificateMigration(3)->up();

    expect(certificateCheckExists('chk_student_certificates_status'))->toBeTrue()
        ->and(certificateCheckExists('chk_student_certificates_revocation'))->toBeTrue();
});

it('rolls back the generated column and its index together, leaving the table', function () {
    // The constraints reference `status`, not the generated column, so step 2
    // reverses without step 3 having to come off first.
    certificateMigration(2)->down();

    expect(Schema::hasColumn('student_certificates', 'valid_enrollment_id'))->toBeFalse()
        ->and(certificateValidIndexExists())->toBeFalse()
        // The table and its own columns survive.
        ->and(Schema::hasTable('student_certificates'))->toBeTrue()
        ->and(Schema::hasColumn('student_certificates', 'reference_number'))->toBeTrue();

    certificateMigration(2)->up();

    expect(Schema::hasColumn('student_certificates', 'valid_enrollment_id'))->toBeTrue()
        ->and(certificateValidIndexExists())->toBeTrue();
});

it('drops the whole table on the first migration down', function () {
    certificateMigration(3)->down();
    certificateMigration(2)->down();
    certificateMigration(1)->down();

    expect(Schema::hasTable('student_certificates'))->toBeFalse();
});

it('rebuilds the whole register from nothing, in order', function () {
    /*
     * The forward path from an empty schema, which is what a fresh install and
     * every CI run actually do. Asserted at each boundary rather than only at
     * the end, so a step that silently did another step's work would show up.
     */
    certificateMigration(3)->down();
    certificateMigration(2)->down();
    certificateMigration(1)->down();

    certificateMigration(1)->up();
    expect(Schema::hasTable('student_certificates'))->toBeTrue()
        ->and(Schema::hasColumn('student_certificates', 'valid_enrollment_id'))->toBeFalse()
        ->and(certificateCheckExists('chk_student_certificates_status'))->toBeFalse();

    certificateMigration(2)->up();
    expect(certificateValidIndexExists())->toBeTrue()
        ->and(certificateCheckExists('chk_student_certificates_status'))->toBeFalse();

    certificateMigration(3)->up();
    expect(certificateCheckExists('chk_student_certificates_status'))->toBeTrue()
        ->and(certificateCheckExists('chk_student_certificates_revocation'))->toBeTrue();
});
