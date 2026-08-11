<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\EnrollStudentAction;
use App\Domain\Enrollment\Data\EnrollStudentData;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The four `enrollments.reference` migrations, driven directly
|--------------------------------------------------------------------------
|
| Design section 2 splits add-nullable / backfill / index / tighten into four
| migrations precisely so that each is independently recoverable: MySQL does not
| roll back DDL, so a migration holding two schema statements can fail on the
| second having already committed the first, and the retry then dies on the
| first. Revision 3 combined steps 3 and 4 and produced exactly that state.
|
| A split justified by what happens after a partial failure can only be tested
| by producing a partial failure. So this file runs the migrations itself, in
| the states a crash would leave behind, rather than observing the schema a
| finished `migrate` produced.
|
| WHY DatabaseTruncation AND NOT RefreshDatabase
| ----------------------------------------------
| Every test here issues DDL, and MySQL commits DDL implicitly. Under
| RefreshDatabase — which wraps each test in one transaction and rolls it back —
| the first ALTER would commit the wrapper, and every row the test wrote would
| survive into whichever file runs next on a database all worktrees share.
| That is precisely the pollution tests/Feature/DatabaseIsolationTest.php exists
| to prevent.
|
| DatabaseTruncation gives these tests production transaction behaviour without
| rebuilding the schema for every case: Laravel migrates once, then truncates
| row data before the next test. The afterEach below restores the four migration
| steps to their fully-applied state before truncation hands that schema onward.
|
| The `afterEach` below is load-bearing; see its comment.
*/

uses(DatabaseTruncation::class);

/**
 * The four steps, in order, by their migration names.
 *
 * Keyed by step number so a test reads as "step 3", the way the design and the
 * migrations' own docblocks refer to them.
 *
 * @var array<int, string>
 */
const REFERENCE_MIGRATIONS = [
    1 => '2026_08_10_000100_add_reference_to_enrollments_table',
    2 => '2026_08_10_000200_backfill_enrollment_references',
    3 => '2026_08_10_000300_add_reference_unique_index_to_enrollments_table',
    4 => '2026_08_10_000400_make_enrollments_reference_not_nullable',
];

/** The unique index step 3 owns, under the name Laravel generates for it. */
const REFERENCE_UNIQUE_INDEX = 'enrollments_reference_unique';

/**
 * One of the four migrations, as an object whose up() and down() can be called.
 *
 * `require` rather than `require_once`: each of these files ends in
 * `return new class extends Migration`, so every evaluation yields a fresh
 * instance — which is how Laravel's own Migrator loads them. `require_once`
 * would return `true` on the second call and this helper would hand back a
 * boolean.
 */
function referenceMigration(int $step): Migration
{
    /** @var Migration $migration */
    $migration = require base_path('database/migrations/'.REFERENCE_MIGRATIONS[$step].'.php');

    return $migration;
}

/**
 * Undo all four, newest first, leaving `enrollments` as phase 1 shipped it.
 *
 * Through the migrations' own down() methods rather than by dropping the column
 * directly, so the rollback path is exercised rather than assumed.
 */
function referenceRewind(): void
{
    foreach ([4, 3, 2, 1] as $step) {
        referenceMigration($step)->down();
    }
}

/**
 * Is `enrollments.reference` nullable right now?
 *
 * Selected with an alias because MySQL returns `information_schema` column
 * names uppercased, so reading `$row->is_nullable` dies on a missing property.
 */
function referenceColumnIsNullable(): bool
{
    return DB::table('information_schema.columns')
        ->select('is_nullable as nullable')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'enrollments')
        ->where('column_name', 'reference')
        ->value('nullable') === 'YES';
}

/** The column's declared type, e.g. `varchar(64)`. */
function referenceColumnType(): ?string
{
    $type = DB::table('information_schema.columns')
        ->select('column_type as declared_type')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'enrollments')
        ->where('column_name', 'reference')
        ->value('declared_type');

    return $type === null ? null : (string) $type;
}

/**
 * Every UNIQUE index covering `enrollments.reference`, by name.
 *
 * A list rather than a boolean, because "exactly one" is the assertion that
 * matters after a retry — a second index under a second name would be the
 * duplicated-DDL failure this split exists to prevent.
 *
 * @return array<int, string>
 */
function referenceUniqueIndexes(): array
{
    return DB::table('information_schema.statistics')
        ->select('index_name as name')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'enrollments')
        ->where('column_name', 'reference')
        ->where('non_unique', 0)
        ->distinct()
        ->pluck('name')
        ->map(fn (mixed $name): string => (string) $name)
        ->all();
}

/**
 * An enrolment row that predates the column, inserted raw.
 *
 * `reference` is OMITTED rather than set to null, which is what a row written
 * before step 1 ran actually looks like. Raw, because Enrollment::factory()
 * mints a real reference in afterCreating() — a fixture that fills the column
 * cannot stand in for a row that has never had one.
 *
 * $enrolledAt is a UTC string, the way the column is stored and read.
 */
function referencePreExistingEnrollment(string $enrolledAt): int
{
    return (int) DB::table('enrollments')->insertGetId([
        'student_id' => Student::factory()->create()->getKey(),
        'batch_id' => Batch::factory()->create()->getKey(),
        'enrolled_at' => $enrolledAt,
        'status' => EnrollmentStatus::Active->value,
        'completed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The reference currently on a row, or null. */
function referenceOf(int $id): ?string
{
    $value = DB::table('enrollments')->where('id', $id)->value('reference');

    return $value === null ? null : (string) $value;
}

afterEach(function () {
    /*
     * LOAD-BEARING, not tidiness. DatabaseTruncation preserves the schema and
     * clears only row data between tests. A test that left step 3
     * recorded-but-not-applied would therefore hand a false schema to the next
     * case, where the failure would surface far from its cause.
     *
     * So each test may leave the schema wherever its scenario needs it, and this
     * puts it back where the `migrations` table claims it is. Every step here is
     * idempotent: step 2 only fills NULLs, and step 4's MODIFY on an already
     * NOT NULL column is the no-op its own docblock records.
     */
    if (! Schema::hasColumn('enrollments', 'reference')) {
        referenceMigration(1)->up();
    }

    referenceMigration(2)->up();

    if (referenceUniqueIndexes() === []) {
        referenceMigration(3)->up();
    }

    referenceMigration(4)->up();

    foreach (REFERENCE_MIGRATIONS as $name) {
        if (! DB::table('migrations')->where('migration', $name)->exists()) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
    }
});

/*
|--------------------------------------------------------------------------
| Step 2 — the backfill
|--------------------------------------------------------------------------
*/

it('backfills a pre-existing row on the centre calendar, not on UTC', function () {
    referenceRewind();
    referenceMigration(1)->up();

    $midYear = referencePreExistingEnrollment('2026-06-15 10:00:00');
    $localNewYear = referencePreExistingEnrollment('2026-12-31 22:30:00');

    /*
     * WHY THAT SECOND TIMESTAMP IS THE ONE THAT MATTERS. 22:30 UTC on 31
     * December is 00:30 on 1 January in Africa/Tripoli, so the stored instant
     * and the centre's calendar disagree about which year the row belongs to.
     *
     * Established here with Carbon directly rather than through CentreCalendar,
     * because a test that derives its expectation from the class under test can
     * only ever agree with it. The migration previously used MySQL's
     * `YEAR(enrolled_at)` and minted ENR-2026- for rows the generator had
     * numbered ENR-2027- — two document numbers for rows the centre cannot tell
     * apart, and this row is that case.
     */
    expect(CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC')->year)->toBe(2026)
        ->and(CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC')->setTimezone('Africa/Tripoli')->year)->toBe(2027);

    expect(referenceOf($midYear))->toBeNull()
        ->and(referenceOf($localNewYear))->toBeNull();

    referenceMigration(2)->up();

    /*
     * The format is spelled out literally — `ENR-`, the year, and the id padded
     * to six — rather than built with Reference::format(). The migration calls
     * that function, so an expectation built from it would pass whatever the
     * function did.
     */
    expect(referenceOf($midYear))->toBe(
        'ENR-2026-'.sprintf('%06d', $midYear),
        'A row enrolled in June was not numbered as a 2026 enrolment.',
    )->and(referenceOf($localNewYear))->toBe(
        'ENR-2027-'.sprintf('%06d', $localNewYear),
        'A row enrolled at 00:30 local on 1 January was numbered by the UTC year. The '
        .'backfill and EnrollStudentAction now disagree about what this row is called.',
    );
});

it('backfills only the rows that carry no reference, however often it runs', function () {
    referenceRewind();
    referenceMigration(1)->up();

    $untouched = referencePreExistingEnrollment('2026-03-01 09:00:00');
    $corrected = referencePreExistingEnrollment('2026-03-02 09:00:00');

    referenceMigration(2)->up();

    $untouchedReference = referenceOf($untouched);
    $backfilledOntoCorrected = referenceOf($corrected);

    /*
     * A value the backfill would never produce for this row, standing in for
     * anything written outside it — a correction, a legacy import, a
     * placeholder mid-transaction. `WHERE reference IS NULL` is the only thing
     * that protects it, and a second pass is where an unfiltered UPDATE would
     * show up.
     */
    DB::table('enrollments')->where('id', $corrected)->update(['reference' => 'ENR-1999-000001']);

    // A row that arrived after the first pass — the interrupted-and-resumed case.
    $arrivedLater = referencePreExistingEnrollment('2026-04-05 09:00:00');

    referenceMigration(2)->up();

    expect($backfilledOntoCorrected)->not->toBe(
        'ENR-1999-000001',
        'The sentinel happens to equal what the backfill produces for this row, so this test '
        .'could not tell an overwrite from a correct value.',
    );

    expect(referenceOf($untouched))->toBe(
        $untouchedReference,
        'A second pass rewrote a row it had already numbered.',
    )->and(referenceOf($corrected))->toBe(
        'ENR-1999-000001',
        'The backfill overwrote a reference it did not write. `WHERE reference IS NULL` is '
        .'what makes this migration re-runnable, and without it a re-run renumbers documents '
        .'already in a student\'s hands.',
    )->and(referenceOf($arrivedLater))->toBe(
        'ENR-2026-'.sprintf('%06d', $arrivedLater),
        'A row inserted between the two passes was left with no reference.',
    )->and(DB::table('enrollments')->whereNull('reference')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The order, and what each step is unable to do out of it
|--------------------------------------------------------------------------
*/

it('reaches NOT NULL UNIQUE only once all four steps have run in order', function () {
    referenceRewind();

    expect(Schema::hasColumn('enrollments', 'reference'))->toBeFalse();

    referenceMigration(1)->up();
    $pre = referencePreExistingEnrollment('2026-05-01 09:00:00');

    expect(referenceColumnIsNullable())->toBeTrue()
        ->and(referenceUniqueIndexes())->toBe([]);

    /*
     * STEP 4 CANNOT PRECEDE STEP 2, and this is why the order is what it is
     * rather than a preference about it. Under STRICT_TRANS_TABLES — which
     * config/database.php turns on — MySQL refuses to make a column NOT NULL
     * while a row holds NULL in it.
     */
    $tooEarly = null;

    try {
        referenceMigration(4)->up();
    } catch (QueryException $exception) {
        $tooEarly = $exception->getMessage();
    }

    /*
     * Positive rather than `->not->toBeNull()`: Pest re-renders a negated
     * expectation's message as a shortened value, so the explanation would be
     * truncated out of the failure.
     */
    expect($tooEarly !== null)->toBeTrue(
        'MySQL accepted NOT NULL on a column still holding a NULL, so the ordering this '
        .'four-way split depends on is not being enforced by the database.',
    );
    expect(str_contains((string) $tooEarly, 'Invalid use of NULL value'))->toBeTrue(
        "Step 4 failed before the backfill, but not on the NULL it should have. MySQL said: {$tooEarly}",
    );

    referenceMigration(2)->up();
    referenceMigration(3)->up();
    referenceMigration(4)->up();

    expect(referenceColumnIsNullable())->toBeFalse()
        ->and(referenceUniqueIndexes())->toBe([REFERENCE_UNIQUE_INDEX])
        /*
         * 'varchar(64)' is a literal here, deliberately, and not
         * Reference::COLUMN_LENGTH. Both migrations read the constant, so
         * reading it here too would make this assertion pass for any value the
         * constant happened to hold — including a wrong one — since the code
         * under test and the check on it would share a single source that
         * could drift and still agree with itself. A literal is the only form
         * of this assertion that can actually catch the constant being changed
         * to something the schema was not also migrated to.
         */
        ->and(referenceColumnType())->toBe(
            'varchar(64)',
            "Step 4's MODIFY redefines the column rather than amending it, so a type that "
            .'does not restate step 1\'s exactly would have quietly resized it.',
        )
        ->and(referenceOf($pre))->toBe('ENR-2026-'.sprintf('%06d', $pre));
});

it('refuses a second run of step 3, which is why it is a file of its own', function () {
    /*
     * THE PREMISE OF THE SPLIT, ASSERTED RATHER THAN CLAIMED. Revision 3 paired
     * the index with the nullability change, which Laravel compiles into two
     * ALTER statements run one after the other; a failure on the second leaves
     * the index created, and the retry dies here. Step 4 is retryable and step 3
     * is not, so they cannot share a file.
     *
     * The schema starts fully migrated, so the index is already there.
     */
    expect(referenceUniqueIndexes())->toBe([REFERENCE_UNIQUE_INDEX]);

    $rerun = null;

    try {
        referenceMigration(3)->up();
    } catch (QueryException $exception) {
        $rerun = $exception->getMessage();
    }

    expect($rerun !== null)->toBeTrue(
        'Step 3 re-ran cleanly. If that is genuinely true, the argument for splitting it from '
        .'step 4 needs revisiting rather than quietly keeping a test that no longer bites.',
    );
    expect(str_contains((string) $rerun, 'Duplicate key name'))->toBeTrue(
        "Step 3's second run failed on something other than its own index name: {$rerun}",
    );

    // The failed retry left the schema exactly as it was — one index, not two.
    expect(referenceUniqueIndexes())->toBe([REFERENCE_UNIQUE_INDEX]);
});

/*
|--------------------------------------------------------------------------
| The recovery the four-way split exists for
|--------------------------------------------------------------------------
*/

it('completes step 4 on a retry after step 3 has already succeeded and been recorded', function () {
    $step4 = REFERENCE_MIGRATIONS[4];

    /*
     * The crash, reproduced: steps 1 to 3 applied AND recorded, step 4 neither.
     * This is the state a machine is left in when the migration process dies
     * between the two — and under the pre-revision-4 design it was the state
     * from which no retry could succeed, because the retry would begin by
     * re-creating an index that already existed.
     */
    referenceMigration(4)->down();
    DB::table('migrations')->where('migration', $step4)->delete();

    expect(referenceColumnIsNullable())->toBeTrue()
        ->and(referenceUniqueIndexes())->toBe([REFERENCE_UNIQUE_INDEX])
        ->and(DB::table('migrations')->where('migration', REFERENCE_MIGRATIONS[3])->exists())->toBeTrue();

    $exit = Artisan::call('migrate', [
        '--path' => 'database/migrations/'.$step4.'.php',
        '--force' => true,
    ]);

    expect($exit)->toBe(0, 'The retry of step 4 failed:'.PHP_EOL.Artisan::output());

    expect(referenceColumnIsNullable())->toBeFalse()
        ->and(referenceUniqueIndexes())->toBe(
            [REFERENCE_UNIQUE_INDEX],
            'The retry produced a second unique index. That is the unrecoverable state the '
            .'four-way split exists to prevent.',
        )
        ->and(DB::table('migrations')->where('migration', $step4)->exists())->toBeTrue();

    /*
     * THE OTHER HALF OF THE CRASH: the ALTER succeeded and the process died
     * before the `migrations` row was written. `migrate` then runs step 4 again
     * against an already NOT NULL column, and the migration's docblock claims
     * that is a no-op MySQL accepts. Claimed, so asserted.
     */
    DB::table('migrations')->where('migration', $step4)->delete();

    $secondExit = Artisan::call('migrate', [
        '--path' => 'database/migrations/'.$step4.'.php',
        '--force' => true,
    ]);

    expect($secondExit)->toBe(
        0,
        'Step 4 is not re-runnable against a column it has already tightened:'.PHP_EOL.Artisan::output(),
    );

    expect(referenceColumnIsNullable())->toBeFalse()
        // A literal, not Reference::COLUMN_LENGTH — see the comment on the
        // first assertion of this shape, above.
        ->and(referenceColumnType())->toBe('varchar(64)')
        ->and(referenceUniqueIndexes())->toBe([REFERENCE_UNIQUE_INDEX]);
});

/*
|--------------------------------------------------------------------------
| A backfilled reference is not distinguishable from a generated one
|--------------------------------------------------------------------------
*/

it('leaves backfilled and generated references identical in format', function () {
    /*
     * The reference is read off paperwork and dictated over the phone, and the
     * format is asserted in tests that do not know which rows came from where.
     * An unpadded backfill written with LPAD was caught in review once already,
     * so this compares the two paths' output rather than each against a pattern
     * one of them might have drifted from.
     */
    referenceRewind();
    referenceMigration(1)->up();

    // Dated now, so both rows fall in the same local calendar year by
    // construction rather than by whatever the machine clock happens to read.
    $preExisting = referencePreExistingEnrollment(CarbonImmutable::now('UTC')->toDateTimeString());

    foreach ([2, 3, 4] as $step) {
        referenceMigration($step)->up();
    }

    // The generator, through the real application path.
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->active()->create(['capacity' => 5]);

    $generated = app(EnrollStudentAction::class)->execute(
        $admin->refresh(),
        new EnrollStudentData(
            (int) Student::factory()->create()->getKey(),
            (int) $batch->getKey(),
        ),
    );

    $backfilled = referenceOf($preExisting);

    expect($backfilled)->toBe('ENR-'.CarbonImmutable::now('Africa/Tripoli')->year.'-'.sprintf('%06d', $preExisting))
        ->and($generated->reference)->toBe(
            'ENR-'.CarbonImmutable::now('Africa/Tripoli')->year.'-'.sprintf('%06d', (int) $generated->getKey()),
        );

    // Same length, same prefix, same year segment — the only difference is the
    // id, which is the only thing that should differ.
    expect(strlen((string) $backfilled))->toBe(strlen((string) $generated->reference))
        ->and(substr((string) $backfilled, 0, 9))->toBe(substr((string) $generated->reference, 0, 9));

    // And the row the application produced satisfies the constraint the backfill
    // made possible: design section 14 requires an enrolment created through the
    // existing path to succeed after the column is non-nullable.
    expect(referenceColumnIsNullable())->toBeFalse()
        ->and(DB::table('enrollments')->whereNull('reference')->count())->toBe(0);
});
