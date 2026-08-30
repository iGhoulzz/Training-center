<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The register's three database constraints (P3-T04)
|--------------------------------------------------------------------------
|
| EVERY INSERT HERE IS A DIRECT DB::table() INSERT, BYPASSING EVERY ACTION.
|
| That is the whole point. T5's Actions take a lock and decide; these prove the
| database refuses regardless — a seeder, a console command or a repair script
| writing a second valid row would corrupt the register silently, and the public
| verifier would then have two answers for one enrolment. The lock protects the
| application path and nothing else.
|
| TWO OF THESE CONSTRAINTS DO NOT MATCH THE SQL THE DESIGN PRINTS, because the
| printed SQL was measured against this project's MySQL 8.4 and does not enforce
| what it claims. See the constraint migration's docblock. These tests assert the
| corrected behaviour, and each one fails if the correction is reverted.
*/

/** A committed enrolment to hang certificates from. */
function certEnrollment(): Enrollment
{
    return Enrollment::factory()->create();
}

/** The columns a valid row needs, so each test varies only what it is about. */
function validCertificateRow(int $enrollmentId, string $reference, array $overrides = []): array
{
    return [
        'enrollment_id' => $enrollmentId,
        'reference_number' => $reference,
        'student_name' => 'Amal Mohammed',
        'course_name' => 'Introductory Accounting',
        'completed_on' => '2026-06-30',
        'issued_at' => '2026-07-01 09:00:00',
        'issued_by' => User::factory()->create()->getKey(),
        'status' => 'valid',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| One valid certificate per enrolment
|--------------------------------------------------------------------------
*/

it('accepts one valid certificate alongside any number of replaced and revoked ones', function () {
    // The generated column carries enrollment_id only while status = 'valid',
    // and a unique index does not collide on NULL — so history accumulates
    // freely while exactly one row stays current.
    $enrollment = certEnrollment();
    $id = (int) $enrollment->getKey();
    $revoker = User::factory()->create()->getKey();

    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-AAAAAAAA'));

    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-BBBBBBBB', [
        'status' => 'replaced',
    ]));
    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-CCCCCCCC', [
        'status' => 'replaced',
    ]));
    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-DDDDDDDD', [
        'status' => 'revoked',
        'revoked_at' => now(),
        'revoked_by' => $revoker,
        'revocation_reason' => 'Issued against the wrong enrolment.',
    ]));

    expect(DB::table('student_certificates')->where('enrollment_id', $id)->count())->toBe(4);
});

it('refuses a second valid certificate for one enrolment', function () {
    $id = (int) certEnrollment()->getKey();

    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-AAAAAAAA'));

    expect(fn () => DB::table('student_certificates')
        ->insert(validCertificateRow($id, 'TC-2026-BBBBBBBB')))
        ->toThrow(QueryException::class);
});

it('lets two different enrolments each hold a valid certificate', function () {
    // The negative control for the rule above. A constraint that refused the
    // second insert unconditionally would pass that test and be useless.
    DB::table('student_certificates')->insert(
        validCertificateRow((int) certEnrollment()->getKey(), 'TC-2026-AAAAAAAA')
    );
    DB::table('student_certificates')->insert(
        validCertificateRow((int) certEnrollment()->getKey(), 'TC-2026-BBBBBBBB')
    );

    expect(DB::table('student_certificates')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| status is one of exactly three values, byte for byte
|--------------------------------------------------------------------------
*/

it('refuses a status outside the value set', function () {
    // A genuine typo. This is the case the design's rationale describes: it
    // yields NULL in valid_enrollment_id and would slip past the unique index
    // entirely, leaving a row neither counted as valid nor visibly wrong.
    $id = (int) certEnrollment()->getKey();

    expect(fn () => DB::table('student_certificates')
        ->insert(validCertificateRow($id, 'TC-2026-AAAAAAAA', ['status' => 'vaild'])))
        ->toThrow(QueryException::class);
});

it('refuses a status that differs only in case', function () {
    /*
     * THE CASE THE DOCUMENTED SQL COULD NOT CATCH.
     *
     * The database collation is utf8mb4_unicode_ci, so the design's
     * `status IN ('valid','revoked','replaced')` returns TRUE for 'Valid' and
     * the row would have been accepted. The constraint uses COLLATE utf8mb4_bin
     * for exactly this.
     *
     * Note also that 'Valid' would NOT have slipped past the unique index the
     * way the design claims — `status = 'valid'` in the generated column is
     * equally case-insensitive — so this row is refused by the status
     * constraint, not by the index.
     */
    $id = (int) certEnrollment()->getKey();

    expect(fn () => DB::table('student_certificates')
        ->insert(validCertificateRow($id, 'TC-2026-AAAAAAAA', ['status' => 'Valid'])))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Revocation fields arrive together, with a reason that is really a reason
|--------------------------------------------------------------------------
*/

it('accepts a revoked row carrying all three revocation fields', function () {
    $id = (int) certEnrollment()->getKey();

    DB::table('student_certificates')->insert(validCertificateRow($id, 'TC-2026-AAAAAAAA', [
        'status' => 'revoked',
        'revoked_at' => now(),
        'revoked_by' => User::factory()->create()->getKey(),
        'revocation_reason' => 'Awarded on a miscounted attendance record.',
    ]));

    expect(DB::table('student_certificates')->count())->toBe(1);
});

it('refuses a revoked row missing any revocation field', function (string $missing) {
    $id = (int) certEnrollment()->getKey();

    $row = validCertificateRow($id, 'TC-2026-AAAAAAAA', [
        'status' => 'revoked',
        'revoked_at' => now(),
        'revoked_by' => User::factory()->create()->getKey(),
        'revocation_reason' => 'Awarded on a miscounted attendance record.',
    ]);
    $row[$missing] = null;

    expect(fn () => DB::table('student_certificates')->insert($row))
        ->toThrow(QueryException::class);
})->with(['revoked_at', 'revoked_by', 'revocation_reason']);

it('refuses a whitespace-only revocation reason', function (string $blank, string $what) {
    /*
     * THE CASE `CHAR_LENGTH(TRIM(...)) > 0` LET THROUGH.
     *
     * Measured on this MySQL: TRIM('   ') is empty, but TRIM("\t") and TRIM("\n")
     * are one character each, so the documented expression accepted a tab or a
     * newline as a "mandatory" reason. The constraint uses
     * REGEXP '[^[:space:]]' instead — at least one non-whitespace character.
     *
     * The tab and newline rows are the ones that would pass against the
     * documented SQL, so they are what makes this test worth running.
     */
    $id = (int) certEnrollment()->getKey();

    expect(fn () => DB::table('student_certificates')->insert(
        validCertificateRow($id, 'TC-2026-AAAAAAAA', [
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => User::factory()->create()->getKey(),
            'revocation_reason' => $blank,
        ])
    ))->toThrow(QueryException::class, '', "A {$what} was accepted as a revocation reason.");
})->with([
    'empty string' => ['', 'empty string'],
    'spaces' => ['   ', 'run of spaces'],
    'tab' => ["\t", 'tab'],
    'newline' => ["\n", 'newline'],
    'tab and newline' => ["\t\n ", 'run of mixed whitespace'],
]);

it('refuses revocation fields on a row that is not revoked', function () {
    // The other half of the constraint. A valid certificate carrying a
    // revocation reason is a contradiction the register must not hold.
    $id = (int) certEnrollment()->getKey();

    expect(fn () => DB::table('student_certificates')->insert(
        validCertificateRow($id, 'TC-2026-AAAAAAAA', [
            'status' => 'valid',
            'revoked_at' => now(),
            'revoked_by' => User::factory()->create()->getKey(),
            'revocation_reason' => 'Revoked, allegedly.',
        ])
    ))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('refuses a duplicate reference number', function () {
    DB::table('student_certificates')->insert(
        validCertificateRow((int) certEnrollment()->getKey(), 'TC-2026-AAAAAAAA')
    );

    expect(fn () => DB::table('student_certificates')->insert(
        validCertificateRow((int) certEnrollment()->getKey(), 'TC-2026-AAAAAAAA')
    ))->toThrow(QueryException::class);
});

it('refuses two certificates claiming the same predecessor', function () {
    // replaces_certificate_id is unique: a replaced certificate can be
    // superseded by at most one later row, so two independent reprints cannot
    // both claim it.
    $first = (int) certEnrollment()->getKey();
    DB::table('student_certificates')->insert(
        validCertificateRow($first, 'TC-2026-AAAAAAAA', ['status' => 'replaced'])
    );
    $predecessor = (int) DB::table('student_certificates')->value('id');

    DB::table('student_certificates')->insert(validCertificateRow($first, 'TC-2026-BBBBBBBB', [
        'replaces_certificate_id' => $predecessor,
    ]));

    expect(fn () => DB::table('student_certificates')->insert(
        validCertificateRow((int) certEnrollment()->getKey(), 'TC-2026-CCCCCCCC', [
            'replaces_certificate_id' => $predecessor,
        ])
    ))->toThrow(QueryException::class);
});

it('refuses to delete an enrolment that holds a certificate', function () {
    // restrictOnDelete, asserted at the database. T6 turns this into a
    // translated business refusal; until then the foreign key is what stops it.
    $enrollment = certEnrollment();
    DB::table('student_certificates')->insert(
        validCertificateRow((int) $enrollment->getKey(), 'TC-2026-AAAAAAAA')
    );

    expect(fn () => DB::table('enrollments')->where('id', $enrollment->getKey())->delete())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The factory, exercised through the model (P3-T04, review round 1)
|--------------------------------------------------------------------------
|
| Every test above inserts through DB::table() on purpose — the constraints must
| hold against a raw insert. The cost is that NOTHING here went through
| StudentCertificate::factory(), so a broken factory resolution passed unnoticed:
| the model used HasFactory without newFactory(), and Laravel looked for
| Database\Factories\Domain\Enrollment\Models\StudentCertificateFactory, which
| does not exist. Found in review.
|
| These exercise the resolution and all three states, so T5 and T7 inherit a
| factory that is known to work rather than assumed to.
*/

it('resolves the factory from the model', function () {
    $certificate = StudentCertificate::factory()->create();

    expect($certificate->exists)->toBeTrue()
        ->and($certificate->status)->toBe(CertificateStatus::Valid)
        ->and($certificate->reference_number)->toStartWith('TC-')
        ->and($certificate->revoked_at)->toBeNull();
});

it('builds a replaced certificate carrying no revocation metadata', function () {
    // The CHECK refuses revocation fields on any non-revoked row, so a state
    // that set the status alone would fail at the database.
    $certificate = StudentCertificate::factory()->replaced()->create();

    expect($certificate->status)->toBe(CertificateStatus::Replaced)
        ->and($certificate->revoked_at)->toBeNull()
        ->and($certificate->revoked_by)->toBeNull()
        ->and($certificate->revocation_reason)->toBeNull();
});

it('builds a revoked certificate with all three revocation fields', function () {
    $certificate = StudentCertificate::factory()->revoked()->create();

    expect($certificate->status)->toBe(CertificateStatus::Revoked)
        ->and($certificate->revoked_at)->not->toBeNull()
        ->and($certificate->revoked_by)->not->toBeNull()
        ->and(trim((string) $certificate->revocation_reason))->not->toBe('');
});

it('dates the reference by Tripoli, from its own issuance instant', function () {
    /*
     * WHAT THIS PROVES, AND WHAT ENFORCES THE REST.
     *
     * Frozen on the boundary: 23:30 UTC on 31 December is already 1 January in
     * Tripoli. So issued_at stores a 2026 instant while the reference must read
     * 2027 — the centre's calendar, not the server's.
     *
     * It does NOT prove the two came from a single reading. A frozen clock
     * returns the same instant however many times it is asked, and an unfrozen
     * one only diverges on a real year boundary, which is not a test that can
     * fail reproducibly. The single-reading requirement is enforced STRUCTURALLY
     * instead: mint() takes the instant as a parameter, so a caller cannot mint
     * without already holding the one it will store. Review round 1 found that
     * mint() used to sample Carbon::now() internally, which is exactly the
     * arrangement this signature makes impossible.
     */
    Carbon::setTestNow('2026-12-31 23:30:00');

    $certificate = StudentCertificate::factory()->create();

    expect(CentreCalendar::yearOf($certificate->issued_at))->toBe(2027)
        ->and($certificate->issued_at->year)->toBe(2026)
        ->and($certificate->reference_number)->toStartWith('TC-2027-');
});

it('gives two certificates different references', function () {
    $first = StudentCertificate::factory()->create();
    $second = StudentCertificate::factory()->create();

    expect($first->reference_number)->not->toBe($second->reference_number);
});
